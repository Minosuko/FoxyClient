<?php

/**
 * FoxyClient Signing Utility
 * Signs FoxyClient.php using an RSA Private Key to generate FoxyClient.sign
 */

$privateKeyFile = "KEY/private.pem";
$targetFile = "FoxyClient.php";
$outputSignFile = "FoxyClient.sign";

echo "--- FoxyClient RSA Signer ---\n";

if (!file_exists($privateKeyFile)) {
    die("ERROR: Private key ($privateKeyFile) not found! Please place your .pem private key in this directory.\n");
}

if (!file_exists($targetFile)) {
    die("ERROR: Target file ($targetFile) not found!\n");
}

echo "Reading Private Key...\n";
$privateKeyContent = file_get_contents($privateKeyFile);
$privateKey = openssl_get_privatekey($privateKeyContent);

if (!$privateKey) {
    die("ERROR: Failed to load private key. Ensure it's a valid PEM format.\n" . openssl_error_string() . "\n");
}

echo "Reading $targetFile...\n";
$data = file_get_contents($targetFile);

echo "Generating RSA-SHA256 signature...\n";
$signature = '';
if (openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
    $base64Signature = base64_encode($signature);
    
    echo "Saving signature to $outputSignFile...\n";
    file_put_contents($outputSignFile, $base64Signature);
    
    echo "\nSUCCESS! FoxyClient.php has been signed.\n";
    echo "Signature Length: " . strlen($base64Signature) . " bytes (Base64)\n";
} else {
    echo "ERROR: Failed to sign data.\n" . openssl_error_string() . "\n";
}

openssl_free_key($privateKey);
