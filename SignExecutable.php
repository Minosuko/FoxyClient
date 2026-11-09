<?php
// SignExecutable.php — Pure-PHP Authenticode (PKCS#7 / SPC) signer.
//
// Uses the certificate + private key in KEY\CODE_SIGNING (by default the
// first *.crt / *.key pair found there, preferring codesign-issuing-ca.*)
// to sign FoxyClient.exe (or any PE32 / PE32+ image).
//
// Usage:
//   php SignExecutable.php                 Sign KEY\CODE_SIGNING cert FoxyClient.exe in place (backup: FoxyClient.exe.bak)
//   php SignExecutable.php <input>         Sign <input> in place
//   php SignExecutable.php <in> <out>      Sign <in>, write to <out>
//   php SignExecutable.php --out <out>     Sign with explicit output
//   php SignExecutable.php --cert <p> --key <p> --out <out> <input>
//   php SignExecutable.php --verify [file] Verify an existing signature
//
// No external tools required (no signtool / osslsigncode) — only the
// openssl extension for RSA signing / verification. Exit code 0 = success.
//
// NOTE: Authenticode requires a valid PE image (MZ header + PE\0\0).
// If the target is not a PE, this script exits with a diagnostic and
// leaves the file untouched.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "SignExecutable.php is a CLI tool.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// DER encoding helpers
// ---------------------------------------------------------------------------

function der_len(int $len): string
{
    if ($len < 128) {
        return chr($len);
    }
    $b = '';
    for ($t = $len; $t > 0; $t >>= 8) {
        $b = chr($t & 0xFF) . $b;
    }
    return chr(0x80 | strlen($b)) . $b;
}

function tlv(int $tag, string $content): string
{
    return chr($tag) . der_len(strlen($content)) . $content;
}

function der_seq(string $content): string
{
    return tlv(0x30, $content);
}

function der_set(string $content): string
{
    return tlv(0x31, $content);
}

function der_oid(string $oid): string
{
    $parts = explode('.', $oid);
    if (count($parts) < 2) {
        throw new InvalidArgumentException("Bad OID: $oid");
    }
    $out = chr(((int) $parts[0]) * 40 + (int) $parts[1]);
    for ($i = 2, $n = count($parts); $i < $n; $i++) {
        $v = (int) $parts[$i];
        $stack = [$v & 0x7F];
        $v >>= 7;
        while ($v > 0) {
            $stack[] = ($v & 0x7F) | 0x80;
            $v >>= 7;
        }
        // Base-128 digits are emitted most-significant first, each but the
        // last with its continuation bit set.
        $stack = array_reverse($stack);
        $last = count($stack) - 1;
        for ($j = 0; $j <= $last; $j++) {
            $stack[$j] = chr($stack[$j] | ($j < $last ? 0x80 : 0));
        }
        $out .= implode('', $stack);
    }
    return tlv(0x06, $out);
}

function der_int_from_content(string $content): string
{
    return tlv(0x02, $content);
}

function der_octet(string $content): string
{
    return tlv(0x04, $content);
}

function der_null(): string
{
    return "\x05\x00";
}

function der_digest_alg_seq(): string
{
    // SHA-256 DigestAlgorithmIdentifier (2.16.840.1.101.3.4.2.1) + NULL params
    return der_seq(der_oid('2.16.840.1.101.3.4.2.1') . der_null());
}

// ---------------------------------------------------------------------------
// Minimal DER parser (single-byte tags only — sufficient for our structures)
// ---------------------------------------------------------------------------

/** @return array{tag:int, headerLen:int, contentLen:int, totalLen:int, content:string} */
function parse_tlv(string $data, int $offset): array
{
    $total = strlen($data);
    if ($offset + 2 > $total) {
        throw new RuntimeException("DER: truncated header at offset $offset");
    }
    $tag = ord($data[$offset]);
    $lenByte = ord($data[$offset + 1]);
    $headerLen = 2;
    if ($lenByte === 0x80) {
        throw new RuntimeException("DER: indefinite length not supported");
    }
    if (($lenByte & 0x80) !== 0) {
        $numLenBytes = $lenByte & 0x7F;
        if ($numLenBytes === 0 || $numLenBytes > 4 || $offset + $headerLen + $numLenBytes > $total) {
            throw new RuntimeException("DER: bad length at offset $offset");
        }
        $contentLen = 0;
        for ($i = 0; $i < $numLenBytes; $i++) {
            $contentLen = ($contentLen << 8) | ord($data[$offset + $headerLen + $i]);
        }
        $headerLen += $numLenBytes;
    } else {
        $contentLen = $lenByte;
    }
    if ($offset + $headerLen + $contentLen > $total) {
        throw new RuntimeException("DER: truncated value at offset $offset");
    }
    return [
        'tag' => $tag,
        'headerLen' => $headerLen,
        'contentLen' => $contentLen,
        'totalLen' => $headerLen + $contentLen,
        'content' => substr($data, $offset + $headerLen, $contentLen),
    ];
}

/**
 * Return the raw TLV slices of the children of a constructed value.
 * @return array<int, array{tag:int, offset:int, totalLen:int, full:string, content:string}>
 */
function parse_children(string $data, int $offset, int $contentLen): array
{
    $out = [];
    $pos = $offset;
    $end = $offset + $contentLen;
    while ($pos < $end) {
        $t = parse_tlv($data, $pos);
        if ($pos + $t['totalLen'] > $end) {
            throw new RuntimeException("DER: child overflows parent at offset $pos");
        }
        $out[] = [
            'tag' => $t['tag'],
            'offset' => $pos,
            'totalLen' => $t['totalLen'],
            'full' => substr($data, $pos, $t['totalLen']),
            'content' => $t['content'],
        ];
        $pos += $t['totalLen'];
    }
    if ($pos !== $end) {
        throw new RuntimeException("DER: children do not exactly fill parent");
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Certificate helpers
// ---------------------------------------------------------------------------

function pem_to_der(string $pem): string
{
    if (!preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m)) {
        throw new RuntimeException("Certificate PEM not found");
    }
    $der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
    if ($der === false) {
        throw new RuntimeException("Certificate base64 decode failed");
    }
    return $der;
}

/**
 * Extract issuer Name (raw DER) and serial INTEGER content from X.509 DER.
 * @return array{issuerDer:string, serialContent:string, subject:string}
 */
function cert_fields(string $certDer): array
{
    $outer = parse_tlv($certDer, 0);
    if ($outer['tag'] !== 0x30 || $outer['totalLen'] !== strlen($certDer)) {
        throw new RuntimeException("Certificate: not a single SEQUENCE");
    }
    $top = parse_children($certDer, $outer['headerLen'], $outer['contentLen']);
    if (count($top) < 1 || $top[0]['tag'] !== 0x30) {
        throw new RuntimeException("Certificate: missing TBSCertificate");
    }
    $tbsOff = $top[0]['offset'] + parse_tlv($certDer, $top[0]['offset'])['headerLen'];
    $tbsLen = parse_tlv($certDer, $top[0]['offset'])['contentLen'];
    $fields = parse_children($certDer, $tbsOff, $tbsLen);
    $i = 0;
    if (count($fields) >= 1 && $fields[0]['tag'] === 0xA0) {
        $i = 1; // version [0] EXPLICIT
    }
    if (count($fields) < $i + 5 || $fields[$i]['tag'] !== 0x02 || $fields[$i + 2]['tag'] !== 0x30) {
        throw new RuntimeException("Certificate: unexpected TBSCertificate shape");
    }
    $serialContent = $fields[$i]['content'];
    $issuerDer = $fields[$i + 2]['full'];

    $subject = '';
    $certPem = "-----BEGIN CERTIFICATE-----\n"
        . chunk_split(base64_encode($certDer), 64, "\n")
        . "-----END CERTIFICATE-----\n";
    $x = @openssl_x509_parse($certPem);
    if (is_array($x) && isset($x['name'])) {
        $subject = (string) $x['name'];
    }
    return ['issuerDer' => $issuerDer, 'serialContent' => $serialContent, 'subject' => $subject];
}

function assert_key_matches_cert(string $keyPem, string $certDer): void
{
    $priv = openssl_pkey_get_private($keyPem);
    if ($priv === false) {
        throw new RuntimeException("Private key is not parseable");
    }
    $privDetails = openssl_pkey_get_details($priv);
    $certX = @openssl_x509_parse($certDer);
    $certPub = @openssl_pkey_get_public($certX['name'] ?? '');
    // openssl_x509_parse cannot give the public key; re-read via PEM export.
    $certPem = "-----BEGIN CERTIFICATE-----\n"
        . chunk_split(base64_encode($certDer), 64, "\n")
        . "-----END CERTIFICATE-----\n";
    $pub = openssl_pkey_get_public($certPem);
    if ($pub === false) {
        throw new RuntimeException("Certificate public key is not parseable");
    }
    $pubDetails = openssl_pkey_get_details($pub);
    if (($privDetails['key'] ?? '') !== ($pubDetails['key'] ?? '')) {
        throw new RuntimeException("Private key does not match certificate");
    }
    unset($certPub);
}

// ---------------------------------------------------------------------------
// PE parsing
// ---------------------------------------------------------------------------

/**
 * @return array{
 *   is64: bool, checksumOff: int, certEntryOff: int, sizeOfHeaders: int,
 *   certOff: int, certSize: int, fileLen: int, unsignedLen: int
 * }
 */
function parse_pe(string $bytes): array
{
    $fileLen = strlen($bytes);
    if ($fileLen < 0x40) {
        throw new RuntimeException(
            "Not a PE file: too small ($fileLen bytes). "
            . "First bytes: " . bin2hex(substr($bytes, 0, 16))
        );
    }
    if (substr($bytes, 0, 2) !== 'MZ') {
        throw new RuntimeException(
            "Not a PE file: missing MZ signature. "
            . "File is $fileLen bytes, first 16 bytes: " . bin2hex(substr($bytes, 0, 16))
        );
    }
    $peOff = unpack('V', substr($bytes, 0x3C, 4))[1];
    if ($peOff <= 0 || $peOff + 24 > $fileLen) {
        throw new RuntimeException("Not a PE file: bad e_lfanew ($peOff)");
    }
    if (substr($bytes, $peOff, 4) !== "PE\x00\x00") {
        throw new RuntimeException(
            "Not a PE file: missing PE\\0\\0 signature at 0x" . dechex($peOff)
        );
    }
    $numSections = unpack('v', substr($bytes, $peOff + 6, 2))[1];
    $optSize = unpack('v', substr($bytes, $peOff + 20, 2))[1];
    if ($optSize < 96 || $peOff + 24 + $optSize > $fileLen) {
        throw new RuntimeException("Not a PE file: optional header truncated");
    }
    $opt = $peOff + 24;
    $magic = unpack('v', substr($bytes, $opt, 2))[1];
    $is64 = false;
    if ($magic === 0x10B) {
        $ddBase = $opt + 96;
    } elseif ($magic === 0x20B) {
        $is64 = true;
        $ddBase = $opt + 112;
    } else {
        throw new RuntimeException("Not a PE file: unknown optional-header magic 0x" . dechex($magic));
    }
    $checksumOff = $opt + 64;
    $sizeOfHeaders = unpack('V', substr($bytes, $opt + 60, 4))[1];
    $numRvaAndSizes = unpack('V', substr($bytes, $ddBase - 4, 4))[1];
    $certEntryOff = $ddBase + 4 * 8; // DataDirectory[4] = certificate table
    if ($numRvaAndSizes <= 4 || $certEntryOff + 8 > $opt + $optSize + 96) {
        throw new RuntimeException("Not a PE file: no certificate data directory");
    }
    $certOff = unpack('V', substr($bytes, $certEntryOff, 4))[1];
    $certSize = unpack('V', substr($bytes, $certEntryOff + 4, 4))[1];

    $unsignedLen = $fileLen;
    if ($certSize > 0) {
        if ($certOff === 0 || $certOff + $certSize > $fileLen || $certOff < $sizeOfHeaders) {
            throw new RuntimeException("Not a PE file: bogus existing certificate table entry");
        }
        $unsignedLen = $certOff; // strip old WIN_CERTIFICATE; allows re-signing
    } elseif ($certOff !== 0) {
        throw new RuntimeException("Not a PE file: inconsistent certificate table entry");
    }
    if ($sizeOfHeaders <= 0 || $sizeOfHeaders > $unsignedLen) {
        throw new RuntimeException("Not a PE file: SizeOfHeaders out of range");
    }

    return [
        'is64' => $is64,
        'checksumOff' => $checksumOff,
        'certEntryOff' => $certEntryOff,
        'sizeOfHeaders' => $sizeOfHeaders,
        'certOff' => $certOff,
        'certSize' => $certSize,
        'fileLen' => $fileLen,
        'unsignedLen' => $unsignedLen,
        'numSections' => $numSections,
        'sectionTableOff' => $opt + $optSize,
    ];
}

/**
 * Authenticode hash (SHA-256) per MS spec and osslsigncode pe.c
 * (pe_digest_calc_bio): hash = [0..checksum) + [checksum+4..certEntry)
 * + [certEntry+8 .. fileend) CONTIGUOUSLY, where fileend = sigpos on a
 * signed file, else filesize. The checksum and cert-entry (8 bytes) are
 * excluded. No per-section walk: bytes between SizeOfHeaders and the
 * file end (image end = cert table start) are all hashed contiguously,
 * including section-table bytes and any data. On an unsigned file a
 * trailing zero padding to the 8-byte boundary is added.
 */
function authenticode_hash(string $bytes, array $pe): string
{
    $hash = hash_init('sha256');
    $chk = $pe['checksumOff'];
    $certEnt = $pe['certEntryOff'];
    $signed = $pe['certSize'] > 0;
    $fileend = $signed ? $pe['certOff'] : $pe['fileLen'];

    hash_update($hash, substr($bytes, 0, $chk));
    hash_update($hash, substr($bytes, $chk + 4, $certEnt - ($chk + 4)));
    hash_update($hash, substr($bytes, $certEnt + 8, $fileend - ($certEnt + 8)));

    if (!$signed) {
        $pad = (8 - ($fileend % 8)) % 8;
        if ($pad !== 0) {
            hash_update($hash, str_repeat("\x00", $pad));
        }
    }
    return hash_final($hash, true);
}

// ---------------------------------------------------------------------------
// SPC / PKCS#7 construction
// ---------------------------------------------------------------------------

function build_spc(string $imageHash): string
{
    // SpcPeImageData per the Microsoft Authenticode spec:
    //   SEQUENCE { flags BIT STRING (empty), file SpcLink }
    // where SpcLink defaults to the "file" CHOICE: [2] EXPLICIT SpcString,
    // and SpcString defaults to unicode: [0] IMPLICIT BMPString
    // "<<<Obsolete>>>" (UTF-16BE). osslsigncode/SignTool emit this shape;
    // an empty SEQUENCE for `file` is not parseable by WinTrust.
    $bmp = '';
    foreach (mb_str_split('<<<Obsolete>>>', 1, 'UTF-8') as $ch) {
        $bmp .= pack('n', mb_ord($ch, 'UTF-8'));
    }
    $bmpInner = tlv(0x80, $bmp);                 // [0] IMPLICIT BMPString
    // SpcLink: ASN1_CHOICE(SpcLink) = { [0] IMPLICIT IA5String url,
    // [1] IMPLICIT SpcSerializedObject moniker,
    // [2] EXPLICIT SpcString file }. The SpcString CHOICE { [0] IMPLICIT
    // BMPString unicode, [1] IMPLICIT IA5String ascii } with value
    // "<<<Obsolete>>>" therefore encodes as: [2] EXPLICIT [0] unicode.
    $spcLink = tlv(0xA2, $bmpInner);              // [2] EXPLICIT SpcString (file)
    $fileWrap = tlv(0xA0, $spcLink);              // [0] EXPLICIT SpcLink (file in SpcPeImageData)
    // osslsigncode sets BIT 0 (includeResources?) via
    // ASN1_BIT_STRING_set_bit(flags,0,1) -> content 0x80, 7 unused bits.
    // Windows ignores `flags`, but osslsigncode byte-compares the SPC
    // hash inputs — match the canonical encoding exactly.
    $flags = tlv(0x03, "\x07\x80");              // BIT STRING, bit 0 set
    $peImageData = der_seq($flags . $fileWrap);
    // SpcAttributeTypeAndOptionalValue: SEQ { OID, value ASN1_ANY }.
    // The value is the raw SpcPeImageData SEQUENCE (not [0] EXPLICIT):
    // osslsigncode.c defines it ASN1_OPT(..., ASN1_ANY) and real
    // Microsoft signatures (e.g. kernel32.dll) carry valTag=0x30 here.
    // Wintrust fails to recover the image data from a [0]-wrapped value.
    $data = der_seq(der_oid('1.3.6.1.4.1.311.2.1.15') . $peImageData);
    // DigestInfo inside SpcIndirectDataContent
    $digestInfo = der_seq(der_digest_alg_seq() . der_octet($imageHash));
    return der_seq($data . $digestInfo);
}

function build_signed_data(
    string $spc,
    string $certDer,
    string $issuerDer,
    string $serialContent,
    $privKey,
): string {
    // messageDigest attribute hashes the *contents* (value octets) of the
    // SpcIndirectDataContent SEQUENCE, not the SEQUENCE TLV itself
    // (CMS "digest the eContent value", where eContent is the direct
    // SpcIndirectDataContent SEQUENCE in Authenticode).
    $spcLenByte = ord($spc[1]);
    if (($spcLenByte & 0x80) !== 0) {
        $nl = $spcLenByte & 0x7F;
        $spcValue = substr($spc, 2 + $nl);
    } else {
        $spcValue = substr($spc, 2);
    }
    $spcDigest = hash('sha256', $spcValue, true);

    // Authenticated attributes: contentType, messageDigest,
    // SpcStatementType (individualCodeSigning) and signingTime.
    // Windows/CNG requires all four; contentType+messageDigest alone
    // fails PKCS#7 decoding in the Explorer Digital Signatures tab.
    $attrContentType = der_seq(der_oid('1.2.840.113549.1.9.3') . der_set(der_oid('1.3.6.1.4.1.311.2.1.4')));
    $attrMsgDigest = der_seq(der_oid('1.2.840.113549.1.9.4') . der_set(der_octet($spcDigest)));
    $attrStatementType = der_seq(der_oid('1.3.6.1.4.1.311.2.1.11') . der_set(der_seq(der_oid('1.3.6.1.4.1.311.2.1.21'))));
    $utctime = gmdate('ymdHis') . 'Z';            // UTCTime (valid 1950-2049)
    // Microsoft Authenticode signerInfos carry: contentType, messageDigest,
    // SpcStatementType (individualCodeSigning 1.3.6.1.4.1.311.2.1.21) and
    // signtool additionally the SpcSpOpusInfo "extension" (1.3.6.1.4.1.311.2.1.12)
    // SEQ { [0] SpcString "<<<Obsolete>>>" } when signing via osslsigncode
    // helpers; signingTime alone is what osslsigncode writes when -time is
    // given, but the plain default omits signingTime for determinism. Windows
    // Wintrust verifies messageDigest over the SPC correctly either way — the
    // HashMismatch path is driven by image-hash/SPC bytes, not by which of
    // these two optional attrs are present. Keep the four canonical attrs.
    $attrSigningTime = der_seq(der_oid('1.2.840.113549.1.9.5') . der_set(tlv(0x17, $utctime)));
    // KarpelesLab(cm-os): the four signed attrs are the canonical
    // Authenticode set. Keep all four — dropping signingTime does not
    // fix HashMismatch, and omitting it diverges from signtool output.
    $attrs = [$attrContentType, $attrMsgDigest, $attrStatementType, $attrSigningTime];
    usort($attrs, fn($a, $b) => strcmp($a, $b)); // DER SET OF sorting
    $attrsConcat = implode('', $attrs);
    // Signature input: the SET OF encoding (tag 0x31), per PKCS#7/CMS.
    $attrsSet = der_set($attrsConcat);
    $signature = '';
    if (!openssl_sign($attrsSet, $signature, $privKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException("RSA signing failed: " . (openssl_error_string() ?: 'unknown'));
    }
    // In SignerInfo the attributes are [0] IMPLICIT (tag 0xA0).
    $attrsImplicit = tlv(0xA0, $attrsConcat);

    $signerInfo = der_seq(
        der_int_from_content("\x01")
        . der_seq($issuerDer . der_int_from_content($serialContent))
        . der_digest_alg_seq()
        . $attrsImplicit
        . der_seq(der_oid('1.2.840.113549.1.1.1') . der_null()) // rsaEncryption (matches MS Authenticode SignerInfo; sha256WithRSA is rejected as NotSigned by WinTrust)
        . der_octet($signature)
    );

    $encap = der_seq(
        der_oid('1.3.6.1.4.1.311.2.1.4') // SPC_INDIRECT_DATA
        . tlv(0xA0, $spc)                  // [0] EXPLICIT SpcIndirectDataContent (direct, NOT wrapped in OCTET STRING per Authenticode spec)
    );

    $signedData = der_seq(
        der_int_from_content("\x01")
        . der_set(der_digest_alg_seq())
        . $encap
        . tlv(0xA0, $certDer)               // certificates [0] IMPLICIT
        . der_set($signerInfo)
    );

    return der_seq(der_oid('1.2.840.113549.1.7.2') . tlv(0xA0, $signedData));
}

// ---------------------------------------------------------------------------
// Sign / verify operations
// ---------------------------------------------------------------------------

function find_key_files(string $scriptDir): array
{
    $base = $scriptDir . DIRECTORY_SEPARATOR . 'KEY' . DIRECTORY_SEPARATOR . 'CODE_SIGNING';
    $crt = $base . DIRECTORY_SEPARATOR . 'codesign-issuing-ca.crt';
    $key = $base . DIRECTORY_SEPARATOR . 'codesign-issuing-ca.key';
    if (!is_file($crt) || !is_file($key)) {
        $crts = glob($base . DIRECTORY_SEPARATOR . '*.crt') ?: [];
        $keys = glob($base . DIRECTORY_SEPARATOR . '*.key') ?: [];
        if (!$crts || !$keys) {
            throw new RuntimeException("No certificate/key pair found in $base");
        }
        $crt = $crts[0];
        $key = $keys[0];
    }
    return [$crt, $key];
}

function sign_file(string $input, ?string $output, string $certPath, string $keyPath): void
{
    if (!extension_loaded('openssl')) {
        throw new RuntimeException("The openssl extension is required");
    }
    $certPem = file_get_contents($certPath);
    if ($certPem === false) {
        throw new RuntimeException("Cannot read certificate: $certPath");
    }
    $keyPem = file_get_contents($keyPath);
    if ($keyPem === false) {
        throw new RuntimeException("Cannot read private key: $keyPath");
    }
    $certDer = pem_to_der($certPem);
    assert_key_matches_cert($keyPem, $certDer);
    $privKey = openssl_pkey_get_private($keyPem);
    if ($privKey === false) {
        throw new RuntimeException("Private key is not parseable");
    }
    $fields = cert_fields($certDer);
    echo "Signer certificate: " . ($fields['subject'] ?: '(unknown)') . "\n";

    $bytes = file_get_contents($input);
    if ($bytes === false) {
        throw new RuntimeException("Cannot read input: $input");
    }
    $pe = parse_pe($bytes);

    $imageHash = authenticode_hash($bytes, $pe);
    $spc = build_spc($imageHash);
    $pkcs7 = build_signed_data($spc, $certDer, $fields['issuerDer'], $fields['serialContent'], $privKey);

    // WIN_CERTIFICATE (revision 0x0200, type PKCS_SIGNED_DATA 0x0002), 8-byte aligned.
    $pkcs7Len = strlen($pkcs7);
    $dwLength = 8 + $pkcs7Len;
    $dwLength += (8 - ($dwLength % 8)) % 8;

    $unsigned = substr($bytes, 0, $pe['unsignedLen']);
    $certOff = strlen($unsigned) + ((8 - (strlen($unsigned) % 8)) % 8);
    $out = $unsigned
        . str_repeat("\x00", $certOff - strlen($unsigned))
        . pack('Vvv', $dwLength, 0x0200, 0x0002)
        . $pkcs7
        . str_repeat("\x00", $dwLength - 8 - $pkcs7Len);
    if (strlen($out) !== $certOff + $dwLength) {
        throw new RuntimeException("Internal error: output length mismatch");
    }

    // Copy appends the freshly-written CheckSum over the whole signed
    // file (excluding its own field), like osslsigncode/KarpelesLab.
    // Authenticode never hashes this field, so it cannot invalidate the
    // signature — and Windows verifiers cross-check it.
    $checksum = (function (string $data, int $csOff): int {
        $n = strlen($data);
        $sum = 0;
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $b0 = ord($data[$i]);
            $b1 = ord($data[$i + 1]);
            if ($i >= $csOff && $i < $csOff + 4) { $b0 = 0; }
            if ($i + 1 >= $csOff && $i + 1 < $csOff + 4) { $b1 = 0; }
            $sum += $b0 | ($b1 << 8);
            $sum = ($sum & 0xFFFF) + ($sum >> 16);
        }
        if ($i < $n) {
            $b = ord($data[$i]);
            if ($i >= $csOff && $i < $csOff + 4) { $b = 0; }
            $sum += $b;
            $sum = ($sum & 0xFFFF) + ($sum >> 16);
        }
        $sum = ($sum & 0xFFFF) + ($sum >> 16);
        return ($sum + $n) & 0xFFFFFFFF;
    })($out, $pe['checksumOff']);
    $out = substr_replace($out, pack('V', $checksum), $pe['checksumOff'], 4);

    // DataDirectory[4] (certificate table): file offset + size. This entry is
    // excluded from the Authenticode hash, so no circularity.
    $out = substr_replace($out, pack('V', $certOff), $pe['certEntryOff'], 4);
    $out = substr_replace($out, pack('V', $dwLength), $pe['certEntryOff'] + 4, 4);

    // Recompute the checksum after the cert dir write too — the field is
    // never hashed, but the final on-disk value should be self-consistent.
    $checksum2 = (function (string $data, int $csOff): int {
        $n = strlen($data);
        $sum = 0;
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $b0 = ord($data[$i]);
            $b1 = ord($data[$i + 1]);
            if ($i >= $csOff && $i < $csOff + 4) { $b0 = 0; }
            if ($i + 1 >= $csOff && $i + 1 < $csOff + 4) { $b1 = 0; }
            $sum += $b0 | ($b1 << 8);
            $sum = ($sum & 0xFFFF) + ($sum >> 16);
        }
        if ($i < $n) {
            $b = ord($data[$i]);
            if ($i >= $csOff && $i < $csOff + 4) { $b = 0; }
            $sum += $b;
            $sum = ($sum & 0xFFFF) + ($sum >> 16);
        }
        $sum = ($sum & 0xFFFF) + ($sum >> 16);
        return ($sum + $n) & 0xFFFFFFFF;
    })($out, $pe['checksumOff']);
    $out = substr_replace($out, pack('V', $checksum2), $pe['checksumOff'], 4);

    $dest = $output ?? $input;
    if ($output === null) {
        $bak = $input . '.bak';
        if (!copy($input, $bak)) {
            throw new RuntimeException("Cannot create backup: $bak");
        }
        echo "Backup written: $bak\n";
    }
    if (file_put_contents($dest, $out) === false) {
        throw new RuntimeException("Cannot write output: $dest");
    }
    echo "Signed: $dest\n";
    echo "  Authenticode SHA-256: " . bin2hex($imageHash) . "\n";
    echo "  PKCS#7: $pkcs7Len bytes, WIN_CERTIFICATE: $dwLength bytes at 0x" . dechex($certOff) . "\n";
    echo "  File: " . strlen($out) . " bytes\n";
}

/**
 * Extract the embedded SPC, messageDigest and signature from a PKCS#7 blob.
 * @return array{spc:string, spcHash:string, attrsSet:string, signature:string, certDer:string, serialContent:string}
 */
function parse_pkcs7(string $pkcs7): array
{
    $outer = parse_tlv($pkcs7, 0);
    if ($outer['tag'] !== 0x30 || $outer['totalLen'] !== strlen($pkcs7)) {
        throw new RuntimeException("PKCS#7: not a single ContentInfo SEQUENCE");
    }
    $top = parse_children($pkcs7, $outer['headerLen'], $outer['contentLen']);
    if (count($top) !== 2) {
        throw new RuntimeException("PKCS#7: unexpected ContentInfo shape");
    }
    // ContentInfo [0] EXPLICIT wraps SignedData SEQUENCE.
    $c0 = parse_children($pkcs7, $top[1]['offset'] + parse_tlv($pkcs7, $top[1]['offset'])['headerLen'],
        parse_tlv($pkcs7, $top[1]['offset'])['contentLen']);
    if (count($c0) !== 1 || $c0[0]['tag'] !== 0x30) {
        throw new RuntimeException("PKCS#7: SignedData not wrapped in [0] EXPLICIT");
    }
    $sdOff = $c0[0]['offset'] + parse_tlv($pkcs7, $c0[0]['offset'])['headerLen'];
    $sdLen = parse_tlv($pkcs7, $c0[0]['offset'])['contentLen'];
    $sd = parse_children($pkcs7, $sdOff, $sdLen);
    if (count($sd) !== 5 || $sd[0]['tag'] !== 0x02 || $sd[4]['tag'] !== 0x31 || $sd[3]['tag'] !== 0xA0) {
        throw new RuntimeException("PKCS#7: unexpected SignedData shape");
    }
    // EncapContentInfo -> [0] -> SpcIndirectDataContent SEQUENCE.
    // (Older signatures wrapped the SPC in an OCTET STRING; accept both.)
    $encap = parse_children($pkcs7, $sd[2]['offset'] + parse_tlv($pkcs7, $sd[2]['offset'])['headerLen'],
        parse_tlv($pkcs7, $sd[2]['offset'])['contentLen']);
    if (count($encap) !== 2 || $encap[1]['tag'] !== 0xA0) {
        throw new RuntimeException("PKCS#7: unexpected EncapContentInfo shape");
    }
    $oct = parse_children($pkcs7, $encap[1]['offset'] + parse_tlv($pkcs7, $encap[1]['offset'])['headerLen'],
        parse_tlv($pkcs7, $encap[1]['offset'])['contentLen']);
    if (count($oct) !== 1) {
        throw new RuntimeException("PKCS#7: SPC content missing");
    }
    $spc = $oct[0]['tag'] === 0x04 ? $oct[0]['content'] : $oct[0]['full'];
    $certDer = $sd[3]['content'];

    // SignerInfo SET -> single SignerInfo
    $siOff = $sd[4]['offset'] + parse_tlv($pkcs7, $sd[4]['offset'])['headerLen'];
    $siLen = parse_tlv($pkcs7, $sd[4]['offset'])['contentLen'];
    $siSet = parse_children($pkcs7, $siOff, $siLen);
    if (count($siSet) !== 1 || $siSet[0]['tag'] !== 0x30) {
        throw new RuntimeException("PKCS#7: expected single SignerInfo");
    }
    $si = parse_children($pkcs7, $siSet[0]['offset'] + parse_tlv($pkcs7, $siSet[0]['offset'])['headerLen'],
        parse_tlv($pkcs7, $siSet[0]['offset'])['contentLen']);
    if (count($si) !== 6 || $si[3]['tag'] !== 0xA0 || $si[5]['tag'] !== 0x04) {
        throw new RuntimeException("PKCS#7: unexpected SignerInfo shape (authenticated attrs required)");
    }
    $signature = $si[5]['content'];
    $attrsConcat = $si[3]['content'];
    $attrsSet = der_set($attrsConcat);

    // messageDigest attribute value
    $attrs = parse_children($pkcs7, $si[3]['offset'] + parse_tlv($pkcs7, $si[3]['offset'])['headerLen'],
        parse_tlv($pkcs7, $si[3]['offset'])['contentLen']);
    $spcHash = null;
    foreach ($attrs as $attr) {
        $a = parse_children($pkcs7, $attr['offset'] + parse_tlv($pkcs7, $attr['offset'])['headerLen'],
            parse_tlv($pkcs7, $attr['offset'])['contentLen']);
        if (count($a) !== 2 || $a[0]['tag'] !== 0x06) {
            continue;
        }
        $oidT = parse_tlv($pkcs7, $a[0]['offset']);
        $oidBytes = $oidT['content'];
        $first = ord($oidBytes[0]);
        $oid = intdiv($first, 40) . '.' . ($first % 40);
        for ($k = 1, $n = strlen($oidBytes); $k < $n; $k++) {
            $v = ord($oidBytes[$k]) & 0x7F;
            while ($k + 1 < $n && (ord($oidBytes[$k]) & 0x80) !== 0) {
                $k++;
                $v = ($v << 7) | (ord($oidBytes[$k]) & 0x7F);
            }
            $oid .= '.' . $v;
        }
        // DEBUG removed
        if ($oid === '1.2.840.113549.1.9.4' || $oid === '1.2.840.113549.1.9.4.1') {
            $set = parse_children($pkcs7, $a[1]['offset'] + parse_tlv($pkcs7, $a[1]['offset'])['headerLen'],
                parse_tlv($pkcs7, $a[1]['offset'])['contentLen']);
            if (count($set) === 1 && $set[0]['tag'] === 0x04) {
                $spcHash = $set[0]['content'];
            }
        }
    }
    if ($spcHash === null) {
        throw new RuntimeException("PKCS#7: messageDigest attribute missing");
    }

    // Serial from IssuerAndSerialNumber (for a sanity cross-check)
    // SignerInfo: version(0), sid(1), digestAlgorithm(2), signedAttrs(3), sigAlg(4), sig(5)
    $ias = parse_children($pkcs7, $si[1]['offset'] + parse_tlv($pkcs7, $si[1]['offset'])['headerLen'],
        parse_tlv($pkcs7, $si[1]['offset'])['contentLen']);
    if (count($ias) !== 2 || $ias[1]['tag'] !== 0x02) {
        throw new RuntimeException("PKCS#7: unexpected IssuerAndSerialNumber shape");
    }

    return [
        'spc' => $spc,
        'spcHash' => $spcHash,
        'attrsSet' => $attrsSet,
        'signature' => $signature,
        'certDer' => $certDer,
        'serialContent' => $ias[1]['content'],
    ];
}

function verify_file(string $path): void
{
    if (!extension_loaded('openssl')) {
        throw new RuntimeException("The openssl extension is required");
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        throw new RuntimeException("Cannot read file: $path");
    }
    $pe = parse_pe($bytes);
    if ($pe['certSize'] <= 0) {
        throw new RuntimeException("No signature present in $path");
    }
    $winCert = substr($bytes, $pe['certOff'], $pe['certSize']);
    $dwLength = unpack('V', substr($winCert, 0, 4))[1];
    $revision = unpack('v', substr($winCert, 4, 2))[1];
    $type = unpack('v', substr($winCert, 6, 2))[1];
    if ($dwLength !== $pe['certSize'] || $revision !== 0x0200 || $type !== 0x0002) {
        throw new RuntimeException("Malformed WIN_CERTIFICATE header");
    }
    $pkcs7 = substr($winCert, 8, $dwLength - 8);
    // Trim trailing alignment padding (WIN_CERTIFICATE is 8-byte aligned).
    $lenByte = ord($pkcs7[1]);
    if (($lenByte & 0x7F)) {
        $numLenBytes = $lenByte & 0x7F;
        $total = 2 + $numLenBytes;
        $contentLen = 0;
        for ($i = 0; $i < $numLenBytes; $i++) {
            $contentLen = ($contentLen << 8) | ord($pkcs7[2 + $i]);
        }
        $total += $contentLen;
        if ($total < strlen($pkcs7)) {
            $pkcs7 = substr($pkcs7, 0, $total);
        }
    }
    $data = parse_pkcs7($pkcs7);

    // 1. Recompute the Authenticode image hash and compare with the SPC digest.
    $recomputed = authenticode_hash($bytes, $pe);
    $spcOuter = parse_tlv($data['spc'], 0);
    if ($spcOuter['tag'] !== 0x30) {
        throw new RuntimeException("SPC: not a SEQUENCE");
    }
    $spcTop = parse_children($data['spc'], $spcOuter['headerLen'], $spcOuter['contentLen']);
    if (count($spcTop) !== 2) {
        throw new RuntimeException("SPC: unexpected shape");
    }
    $di = parse_children($data['spc'], $spcTop[1]['offset'] + parse_tlv($data['spc'], $spcTop[1]['offset'])['headerLen'],
        parse_tlv($data['spc'], $spcTop[1]['offset'])['contentLen']);
    if (count($di) !== 2 || $di[1]['tag'] !== 0x04) {
        throw new RuntimeException("SPC: bad DigestInfo");
    }
    if (!hash_equals($recomputed, $di[1]['content'])) {
        throw new RuntimeException(
            "Authenticode hash mismatch: file was modified after signing"
        );
    }
    echo "Authenticode image hash OK: " . bin2hex($recomputed) . "\n";

    // 2. messageDigest attribute must equal SHA-256(SPC contents).
    $spcLenByte = ord($data['spc'][1]);
    $spcValue = (($spcLenByte & 0x80) !== 0) ? substr($data['spc'], 2 + ($spcLenByte & 0x7F)) : substr($data['spc'], 2);
    if (!hash_equals(hash('sha256', $spcValue, true), $data['spcHash'])) {
        throw new RuntimeException("messageDigest attribute mismatch");
    }
    echo "messageDigest attribute OK\n";

    // 3. RSA signature over the authenticated attributes.
    $certPem = "-----BEGIN CERTIFICATE-----\n"
        . chunk_split(base64_encode($data['certDer']), 64, "\n")
        . "-----END CERTIFICATE-----\n";
    $pub = openssl_pkey_get_public($certPem);
    if ($pub === false) {
        throw new RuntimeException("Embedded certificate is not parseable");
    }
    $ok = openssl_verify($data['attrsSet'], $data['signature'], $pub, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) {
        throw new RuntimeException("RSA signature verification failed");
    }
    $fields = cert_fields($data['certDer']);
    if ($fields['serialContent'] !== $data['serialContent']) {
        throw new RuntimeException("Signer serial does not match embedded certificate");
    }
    echo "RSA signature OK (signer: " . ($fields['subject'] ?: '(unknown)') . ")\n";
    echo "Verified: $path\n";
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

function usage(): void
{
    fwrite(STDERR,
        "Usage:\n"
        . "  php SignExecutable.php [--verify] [input] [output]\n"
        . "  php SignExecutable.php --cert <crt> --key <key> --out <out> <input>\n"
        . "  php SignExecutable.php --verify <file>\n");
}

$args = array_slice($argv, 1);
$verify = false;
$certOpt = null;
$keyOpt = null;
$outOpt = null;
$paths = [];
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--verify' || $a === '-v') {
        $verify = true;
    } elseif ($a === '--cert') {
        $certOpt = $args[++$i] ?? null;
    } elseif ($a === '--key') {
        $keyOpt = $args[++$i] ?? null;
    } elseif ($a === '--out') {
        $outOpt = $args[++$i] ?? null;
    } elseif ($a === '--help' || $a === '-h') {
        usage();
        exit(0);
    } elseif (str_starts_with($a, '--cert=')) {
        $certOpt = substr($a, 7);
    } elseif (str_starts_with($a, '--key=')) {
        $keyOpt = substr($a, 6);
    } elseif (str_starts_with($a, '--out=')) {
        $outOpt = substr($a, 6);
    } elseif (str_starts_with($a, '--')) {
        fwrite(STDERR, "Unknown option: $a\n");
        usage();
        exit(2);
    } else {
        $paths[] = $a;
    }
}
if (count($paths) > 2) {
    fwrite(STDERR, "Too many positional arguments\n");
    usage();
    exit(2);
}

$scriptDir = __DIR__;
$input = $paths[0] ?? ($scriptDir . DIRECTORY_SEPARATOR . 'FoxyClient.exe');
$output = $outOpt ?? ($paths[1] ?? null);

try {
    if ($verify) {
        verify_file($input);
    } else {
        [$defCrt, $defKey] = find_key_files($scriptDir);
        sign_file($input, $output, $certOpt ?? $defCrt, $keyOpt ?? $defKey);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
