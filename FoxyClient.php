<?php

/**
 * Foxy Client - Native OpenGL GUI
 * Implemented using PHP FFI for Win32 and OpenGL32
 * Features: Tabbed UI, smooth scrolling, hover effects
 */

class FoxyClient
{
	public const VERSION = "1.3.4";
	private $kernel32;
	private $user32;
	private $gdi32;
	private $opengl32;
	private $gdiplus;
	private $gdiplusToken;

	private $hwnd;
	private $hdc;
	private $hglrc;
	private $config;
	private $running = true;
	private $width = 1200;
	private $height = 700;

	private $wndProc;
	private $wndProcClassName;
	private $fontAtlas = []; // listBase => [texId, glyphs, height, atlasW]
	private $logoTex = 0;
	private $mojangTex = 0;
	private $elybyTex = 0;
	private $bgTex = null;
	private $bgW = 0,
		$bgH = 0;
	private $logoW = 0;
	private $logoH = 0;

	// Tab system
	private $tabs = []; // ['name' => string, 'mods' => [...]]
	private $activeTab = 0;
	private $tabHover = -1;

	// Page system
	private const PAGE_LOGIN = 0;
	private const PAGE_VERSIONS = 1;
	private const PAGE_MODS = 2;
	private const PAGE_ACCOUNTS = 3;
	private const PAGE_PROPERTIES = 4;
	private const PAGE_HOME = 5;
	private const PAGE_FOXYCLIENT = 6;

	// Account types
	private const ACC_OFFLINE = "offline";
	private const ACC_MICROSOFT = "microsoft";
	private const ACC_ELYBY = "elyby";
	private const ACC_FOXY = "foxy";
	private const ACC_MOJANG = "mojang";

	private $currentPage = self::PAGE_HOME;
	private $sidebarHover = -1;
	private $sidebarItems = [
		["id" => self::PAGE_HOME, "name" => "HOME"],
		["id" => self::PAGE_FOXYCLIENT, "name" => "FOXYCLIENT"],
		["id" => self::PAGE_ACCOUNTS, "name" => "ACCOUNTS"],
		["id" => self::PAGE_MODS, "name" => "MODPACKS"],
		["id" => self::PAGE_VERSIONS, "name" => "VERSIONS"],
		["id" => self::PAGE_PROPERTIES, "name" => "PROPERTIES"],
	];

	// Layout constants
	private const HEADER_H = 70;
	private const TAB_H = 40;
	private const FOOTER_H = 150;
	private const CARD_H = 44;
	private const CARD_GAP = 6;
	private const PAD = 16;
	private const SIDEBAR_W = 200;
	private const DATA_DIR = "FoxyClient";
	private const CACHE_DIR = self::DATA_DIR . DIRECTORY_SEPARATOR . "data";
	private const CACERT =
		self::DATA_DIR .
		DIRECTORY_SEPARATOR .
		"config" .
		DIRECTORY_SEPARATOR .
		"cacert.pem";
	private const LOG_DIR = self::DATA_DIR . DIRECTORY_SEPARATOR . "logs";
	private const LATEST_LOG =
		self::LOG_DIR . DIRECTORY_SEPARATOR . "latest.log";

	private const ELY_ENDPOINT = "ely.by";
	private const FOXY_ENDPOINT = "foxyclient.qzz.io";

	// Login system state
	private $loginType = self::ACC_OFFLINE; // current sub-mode of login
	private $loginStep = 0; // 0=select, 1=input/process
	private $loginInputPassword = "";
	private $msDeviceCode = "";
	private $msUserCode = "";
	private $msVerificationUri = "";
	private $msPollingInterval = 5;
	private $msLastPollTime = 0;
	private $msError = "";

	private $elyClientId = "foxyclientwo2";
	private $elyClientSecret = "EdA4PVqtNB07cjxsDkJZLjknJmdJwYAwXnpJSZgC0UrOzbSHwNzXI_1hdxq-usgk";

	// FoxyClient OAuth
	private $foxyClientId = "foxyapp";
	private $foxyClientSecret = "foxysecret_123";
	private $foxyRedirectUri = "http://localhost:25564/callback";

	private $loginInputCode = "";
	private $elyLoginMethod = "oauth2"; // oauth2 or classic
	private $foxyLoginMethod = "oauth2"; // oauth2 or classic
	private $oauthServer = null;
	private $oauthPort = 25564;
	private $oauthState = "";


	// Smooth scroll
	private $scrollOffset = 0.0;
	private $scrollTarget = 0.0;
	private $scrollSpeed = 0.15;

	// Mouse state
	private $mouseX = 0;
	private $mouseY = 0;
	private $hoverModIndex = -1;
	private $buttonHover = false;

	// Account system
	private $accounts = [];
	private $activeAccount = "";
	private $isLoggedIn = false;
	private $loginInput = "";
	private $inputFocus = false;
	private $loginButtonHover = false;
	private $accHoverIndex = -1;
	private $accountName = "";
	private $loginInputTOTP = "";
	private $isFetchingVersions = false;

	// Version system
	private $versions = [];
	private $versionsLoaded = false;
	private $versionsScroll = 0;
	private $selectedVersion = "";
	private $vHoverIndex = -1;
	private $vCategory = 0; // 0=Release, 1=Snapshot
	private $vScrollTarget = 0;
	private $vScrollOffset = 0;
	private $vTabHover = -1;
	private $filteredVersionsCache = null;
	private $lastVCategory = -1;
	private $manifestRefreshed = false;

	// Mods page version/loader selector
	private $modsVerDropdownOpen = false;
	private $modsVerDropdownAnim = 0.0;
	private $modsVerScrollTarget = 0;
	private $modsVerScrollOffset = 0;
	private $modsVerHoverIdx = -1;
	private $modsLoaderOptions = ["fabric", "forge", "quilt", "neoforge"];

	// Mods page filter dropdowns
	private $modsFilterCategory = ""; // Selected category slug (empty = all)
	private $modsFilterLoader = "";   // Selected loader filter (empty = uses config["loader"])
	private $modsFilterEnv = "";      // "client" or "server" (empty = all)
	private $modsFilterDropdown = ""; // Which dropdown is open: "category"|"loader"|"env"|"version"|""
	private $modsFilterDropdownAnim = 0.0;
	private $modsFilterHoverIdx = -1;
	private $modsFilterScrollTarget = 0;
	private $modsFilterScrollOffset = 0;
	private $lastModrinthCategory = null;
	private $lastModrinthEnv = null;
	private $modsFilterPillRects = []; // Pill rects for click handling [key => [x,y,w,h]]

	// Properties Update Tab state
	private $isUpdatingCacert = false;
	private $caUpdateProgress = 0.0;
	private $isCheckingUiUpdate = false;
	private $hasUiUpdate = false;
	private $updateMessage = "";
	private $updateChannel = null;

	private $modsCategories = [
		"adventure", "cursed", "decoration", "economy", "equipment", "food",
		"game-mechanics", "library", "magic", "management", "minigame", "mobs",
		"optimization", "social", "storage", "technology", "transportation",
		"utility", "world-generation"
	];
	private $modsCategoryLabels = [
		"adventure" => "Adventure", "cursed" => "Cursed", "decoration" => "Decoration",
		"economy" => "Economy", "equipment" => "Equipment", "food" => "Food",
		"game-mechanics" => "Game Mechanics", "library" => "Library",
		"magic" => "Magic", "management" => "Management", "minigame" => "Minigame",
		"mobs" => "Mobs", "optimization" => "Optimization", "social" => "Social",
		"storage" => "Storage", "technology" => "Technology",
		"transportation" => "Transportation", "utility" => "Utility",
		"world-generation" => "World Generation"
	];
	private $modsLoaderList = [
		"fabric", "forge", "neoforge", "quilt", "liteloader", "rift"
	];
	private $modsLoaderLabels = [
		"fabric" => "Fabric", "forge" => "Forge", "neoforge" => "NeoForge",
		"quilt" => "Quilt", "liteloader" => "LiteLoader", "rift" => "Rift"
	];

	private $modCompatCache = []; // mod_id => 'compatible'|'incompatible'|'checking'
	private $isCheckingCompat = false;
	private $compatProcess = null;
	private $compatFuture = null;
	private $compatChannel = null;
	private $ffibuf = []; // Reusable FFI buffers

	// Asset & Mod download parallel state
	private $isDownloadingAssets = false;
	private $assetProgress = 0.0;
	private $assetButtonHover = false;
	private $assetUninstallHover = false;
	private $isLaunching = false;
	private $launchStartTime = 0;
	private $process = null; // \parallel\Runtime for mods
	private $modFuture = null;
	private $modChannel = null;
	private $assetProcess = null; // \parallel\Runtime for assets
	private $assetFuture = null;
	private $assetChannel = null;
	private $pollEvents = null; // \parallel\Events
	private $assetMessage = "";

	// Modrinth Search State
	private $foxySubTab = 0; // 0=Modpack, 1=Keybinds, 2=Macros, 3=Config, 4=Cosmetics, 5=OSD
	private $modpackSubTab = 0; // 0=Mods, 1=Modpacks
	private $modSearchQuery = "";
	private $lastModrinthQuery = null; // Query associated with current cache
	private $lastModrinthSubTab = null; // 0=Mods, 1=Modpacks associated with cache
	private $lastModrinthMCVer = null; // MC version associated with cache
	private $lastModrinthLoader = null; // Loader associated with cache
	private $modSearchDebounceTimer = 0; // Timestamp when debounce finishes
	private $modSearchFocus = false;
	private $isSearchingModrinth = false;
	private $modrinthSearchResults = [];
	private $modIconProgress = [];
	private $modIconAlpha = []; // Per-project icon fade-in alpha
	private $modrinthProcess = null;
	private $modrinthFuture = null;
	private $modrinthChannel = null;
	private $modrinthError = "";
	private $subTabHoverIdx = -1;
	private $modrinthPage = 0;
	private $modrinthPrefetchPage = -1; // Track which page is being prefetched
	private $modPageDebounceTimer = 0; // Timer for Next/Prev button debouncing
	private $modPageTarget = 0; // Destination page during rapid clicking
	private $modrinthTotalHits = 0;
	private $modrinthAnim = 1.0;
	private $modrinthTargetAnim = 1.0;
	private $modrinthResultCache = []; // page => hits
	private $isPrefetching = false;
	private $pendingFutures = []; // Keep old Futures alive to prevent blocking destructors

	// Modrinth Download State (Concurrent)
	private $modDownloadChannels = []; // projectId => Channel
	private $modDownloadRuntimes = []; // projectId => Runtime
	private $modDownloadFutures = []; // projectId => Future
	private $modDownloadProgresses = []; // projectId => float
	private $channelToModId = []; // channelSource => projectId
	private $foxyPreviewZoom = 1.0;

	// Installed Modpacks State
	private $installedModpacks = []; // slug => {name, version, mc_version, loader, files: [filenames]}
	private $modpackInstallProgress = "";
	private $modpackUninstallHover = -1;
	private $modpackInstallProcess = null;
	private $modpackInstallChannel = null;
	private $modpackInstallFuture = null;
	private $isInstallingModpack = false;
	

	// Icon cache
	private $modIconCache = []; // project_id => gl_texture_id
	private $modpackIconCache = []; // slug => gl_texture_id
	private $modIconLastUse = []; // project_id => timestamp
	private $iconDownloadProcess = null;
	private $iconDownloadChannel = null;
	private $iconCancelChannel = null; // Channel for stopping stale downloads
	private $iconDownloadFuture = null; // Reference to prevent "return ignored" fatal error
	private $httpResultChannel = null;
	private $httpQueueChannel = null;
	private $httpWorkerProcess = null;
	private $httpResults = [];
	private $httpPending = [];
	// Manifest fetch state
	private $vManifestProcess = null;
	private $vManifestFuture = null;
	private $vManifestChannel = null;
	private $vManifestError = "";
	private $gameProcess = null;
	private $gameChannel = null;
	private $pollProcessInterval = 0.1;
	private $pollProcessLastTime = 0;

	// Background Runtimes & State
	private $gamePid = null;
	private $maxScroll = 0;

	// FoxyClientMod Installation & Update State
	private $isInstallingFoxyMod = false;
	private $foxyModInstallProcess = null;
	private $foxyModInstallChannel = null;
	private $foxyModInstallFuture = null;
	private $foxyInstallProgress = "";
	private $foxyInstallBtnHover = false;
	private $foxyUpdateBtnHover = false;
	private $foxyModLocalVersion = null; // e.g. "1.3.4"
	private $foxyModLatestVersion = null; // e.g. "1.3.5"
	private $foxyModUpdateAvailable = false;
	private $lastFoxyUpdateCheck = 0;
	private $foxyUpdateChannel = null;
	private $foxyUpdateProcess = null;
	private $foxyUpdateFuture = null;
	private $shouldAutoLaunchAfterDownload = false;

	// Properties system
	private $propSubTab = 0; // 0=Minecraft, 1=Launcher, 2=About
	private $propTabHover = -1;
	private $propFieldHover = -1;
	private $propActiveField = ""; // which text field is focused
	private $propScrollTarget = 0.0;
	private $propScrollOffset = 0.0;
	private $propFontDropdownOpen = ""; // '' = closed, 'launcher' or 'overlay'
	private $propFontDropdownHover = -1;
	private $propResetHover = false;
	private $propSignOutHover = false;
	private $aboutDonateHover = false;
	private $aboutGithubHover = false;
	private $aboutWebsiteHover = false;
	private $aboutContactHover = false;

	// Dragging state
	private $isDraggingScroll = false;
	private $dragType = ""; // 'mods', 'versions', 'prop', 'home_dropdown'
	private $dragStartY = 0;
	private $dragStartOffset = 0;

	// Discord RPC
	private $discord;

	// Java Modal System
	private $javaModalOpen = false;
	private $javaModalActiveField = "";
	private $javaModalHoverIdx = -1;
	private $javaModalDropdownOpen = false;
	private $jvmOptions = [
		"disable" => "Disable",
		"default" => "Default (G1 or CMS)",
		"g1" => "Force G1 GC",
		"shenandoah" => "Force Shenandoah GC",
		"zgc" => "Force ZGC",
	];

	// Background Modal System
	private $bgModalOpen = false;
	private $bgModalHoverIdx = -1;
	private $bgModalActiveField = "";

	// FoxyClient Tab: Keybinds / Macros / FoxyConfig / Cosmetics
	private $foxyKeybindData = [];   // module_name => {enabled, keybind, settings}
	private $foxyMacroData = [];     // keycode => command
	private $foxyConfigData = [];    // setting_key => value
	private $foxyKeybindScrollTarget = 0.0;
	private $foxyKeybindScrollOffset = 0.0;
	private $foxyMacroScrollTarget = 0.0;
	private $foxyMacroScrollOffset = 0.0;
	private $foxyConfigScrollTarget = 0.0;
	private $foxyConfigScrollOffset = 0.0;
	private $foxyKeybindEditIdx = -1;  // Index of keybind being edited (-1 = none)
	private $foxyMacroEditIdx = -1;   // Index of macro being edited
	private $foxyMacroEditCommandIdx = -1;
	private $foxyKeybindHoverIdx = -1;
	private $foxyMacroHoverIdx = -1;
	private $foxyConfigHoverIdx = -1;
	private $foxyCosmeticsHoverIdx = -1;
	private $accScrollTarget = 0.0;
	private $accScrollOffset = 0.0;
	private $foxyKeybindSearchQuery = "";
	private $foxyKeybindSearchFocus = false;
	private $foxyKeybindListenMode = false; // true when waiting for keypress
	private $foxyMacroListenMode = false; // true when waiting for macro keybind
	private $glfw_key_names = [
		-1 => "NONE", 32 => "SPACE", 39 => "'", 44 => ",", 45 => "-", 46 => ".", 47 => "/",
		48 => "0", 49 => "1", 50 => "2", 51 => "3", 52 => "4", 53 => "5",
		54 => "6", 55 => "7", 56 => "8", 57 => "9", 59 => ";", 61 => "=",
		65 => "A", 66 => "B", 67 => "C", 68 => "D", 69 => "E", 70 => "F",
		71 => "G", 72 => "H", 73 => "I", 74 => "J", 75 => "K", 76 => "L",
		77 => "M", 78 => "N", 79 => "O", 80 => "P", 81 => "Q", 82 => "R",
		83 => "S", 84 => "T", 85 => "U", 86 => "V", 87 => "W", 88 => "X",
		89 => "Y", 90 => "Z", 91 => "[", 92 => "\\", 93 => "]", 96 => "`",
		256 => "ESC", 257 => "ENTER", 258 => "TAB", 259 => "BACKSPACE",
		260 => "INSERT", 261 => "DELETE", 262 => "RIGHT", 263 => "LEFT",
		264 => "DOWN", 265 => "UP", 266 => "PGUP", 267 => "PGDN",
		268 => "HOME", 269 => "END",
		280 => "CAPSLOCK", 281 => "SCROLLLOCK", 282 => "NUMLOCK",
		283 => "PRTSC", 284 => "PAUSE",
		290 => "F1", 291 => "F2", 292 => "F3", 293 => "F4", 294 => "F5",
		295 => "F6", 296 => "F7", 297 => "F8", 298 => "F9", 299 => "F10",
		300 => "F11", 301 => "F12",
		340 => "LSHIFT", 341 => "LCTRL", 342 => "LALT", 343 => "LSUPER",
		344 => "RSHIFT", 345 => "RCTRL", 346 => "RALT", 347 => "RSUPER",
	];

	private $settings = [
		"game_dir" => "games",
		"window_w" => "854",
		"window_h" => "480",
		"java_path" => "temurin-jre/bin/javaw.exe",
		"java_args" => "",
		"minecraft_args" => "",
		"jvm_optimizer" => "default",
		"ram_mb" => 2048,
		"bg_file" => self::DATA_DIR . "/images/background.jpg",
		"bg_blur" => 0,
		"theme" => "dark",
		"language" => "en",
		"show_modified_versions" => true,
		"enable_modpack" => false,
		"separate_modpack_folder" => false,
		"overlay_cpu" => false,
		"overlay_gpu" => false,
		"overlay_ram" => false,
		"overlay_vram" => false,
		"font_launcher" => "Open Sans",
		"font_overlay" => "Consolas",
	];

	// Available fonts for selection (populated dynamically from FoxyClient/fonts/*.ttf)
	private $availableFonts = ["Open Sans"];

	// Home Page system
	private $homeAccDropdownOpen = false;
	private $homeVerDropdownOpen = false;
	private $homeHoverIdx = -1;
	private $foxySettingsHoverIdx = -1;
	private $homeVerScrollOffset = 0.0;
	private $homeVerScrollTarget = 0.0;

	// Animation
	private $lastTime;
	private $buttonPulse = 0.0;
	private $pageAnim = 1.0;
	private $homeAccDropdownAnim = 0.0;
	private $homeVerDropdownAnim = 0.0;
	private $javaModalDropdownAnim = 0.0;
	private $globalAlpha = 1.0;
	private $sidebarIndicatorY = 100.0;
	private $sidebarTargetY = 100.0;
	private $sidebarHoverY = 100.0;
	private $sidebarHoverTargetY = 100.0;
	private $sidebarHoverAlpha = 0.0;

	// Window launch animation
	private $windowAnim = 0.0;
	private $appLaunchTime = 0;

	// Dynamic FPS / Idle detection
	private $needsRedraw = true;
	private $isIdle = false;

	// System Metrics Overlay (parallel thread)
	private $overlayThread = null;
	private $overlayChannel = null;
	private $overlayFuture = null;
	private $isStoppingOverlay = false;

	// Custom Window UI
	private const TITLEBAR_H = 32;

	// UI Color Palettes
	private $darkColors = [
		"bg" => [0.03, 0.04, 0.05],
		"panel" => [0.06, 0.08, 0.1],
		"card" => [0.1, 0.12, 0.15],
		"card_hover" => [0.14, 0.18, 0.22],
		"primary" => [0.2, 0.9, 1.0],
		"primary_dim" => [0.15, 0.7, 0.85],
		"accent" => [0.7, 0.95, 1.0],
		"text" => [0.95, 0.98, 1.0],
		"text_dim" => [0.6, 0.65, 0.75],
		"button" => [0.2, 0.9, 1.0],
		"button_hover" => [0.3, 0.95, 1.0],
		"check_off" => [0.2, 0.22, 0.25],
		"tab_bg" => [0.05, 0.07, 0.09],
		"tab_active" => [0.08, 0.12, 0.16],
		"divider" => [0.12, 0.15, 0.18],
		"status_queue" => [0.5, 0.6, 1.0],
		"status_update" => [0.2, 0.9, 1.0],
		"status_done" => [0.3, 1.0, 0.5],
		"status_error" => [1.0, 0.4, 0.3],
		"warning" => [1.0, 0.9, 0.2],
		"sidebar_bg1" => [0.08, 0.08, 0.09],
		"sidebar_bg2" => [0.05, 0.05, 0.06],
		"sidebar_active" => [0.15, 0.15, 0.17],
		"sidebar_hover" => [0.12, 0.14, 0.16],
		"titlebar_bg" => [0.03, 0.03, 0.04],
		"input_bg" => [0.12, 0.12, 0.13],
		"input_bg_active" => [0.15, 0.15, 0.16],
		"button_text" => [0.0, 0.0, 0.0],
		"acc_active" => [0.15, 0.25, 0.35],
		"acc_active_border" => [0.2, 0.8, 1.0],
		"del_btn" => [0.4, 0.1, 0.1],
		"del_btn_hover" => [0.6, 0.15, 0.15],
		"header_bg" => [0.05, 0.05, 0.06],
		"dropdown_bg" => [0.05, 0.06, 0.08, 0.95],
		"dropdown_hover" => [0.12, 0.15, 0.18],
		"info_bg" => [0.12, 0.15, 0.25],
		"subtab" => [0.12, 0.12, 0.14],
		"pill_bg" => [0.1, 0.12, 0.15],
		"pill_active" => [0.2, 0.6, 1.0, 0.4],
		"scrollbar" => [0.2, 0.22, 0.25, 0.5],
		"scrollbar_hover" => [0.3, 0.35, 0.4, 0.7],
		"overlay_bg" => [0.08, 0.09, 0.12, 0.95],
	];

	private $lightColors = [
		"bg" => [0.95, 0.96, 0.97],
		"panel" => [0.9, 0.91, 0.93],
		"card" => [1.0, 1.0, 1.0],
		"card_hover" => [0.98, 0.98, 0.99],
		"primary" => [0.0, 0.5, 0.8],
		"primary_dim" => [0.0, 0.4, 0.7],
		"accent" => [0.2, 0.7, 0.9],
		"text" => [0.1, 0.12, 0.15],
		"text_dim" => [0.4, 0.45, 0.5],
		"button" => [0.0, 0.5, 0.8],
		"button_hover" => [0.0, 0.6, 0.9],
		"check_off" => [0.85, 0.87, 0.9],
		"tab_bg" => [0.94, 0.95, 0.96],
		"tab_active" => [1.0, 1.0, 1.0],
		"divider" => [0.8, 0.82, 0.85],
		"status_queue" => [0.3, 0.4, 0.8],
		"status_update" => [0.0, 0.6, 0.8],
		"status_done" => [0.1, 0.7, 0.3],
		"status_error" => [0.9, 0.2, 0.1],
		"warning" => [0.8, 0.7, 0.0],
		"sidebar_bg1" => [0.92, 0.93, 0.94],
		"sidebar_bg2" => [0.88, 0.89, 0.91],
		"sidebar_active" => [1.0, 1.0, 1.0],
		"sidebar_hover" => [0.96, 0.97, 0.98],
		"titlebar_bg" => [0.94, 0.95, 0.96],
		"input_bg" => [1.0, 1.0, 1.0],
		"input_bg_active" => [0.98, 0.99, 1.0],
		"button_text" => [1.0, 1.0, 1.0],
		"acc_active" => [0.85, 0.92, 1.0],
		"acc_active_border" => [0.0, 0.6, 0.8],
		"del_btn" => [0.95, 0.85, 0.85],
		"del_btn_hover" => [1.0, 0.8, 0.8],
		"header_bg" => [0.9, 0.91, 0.93],
		"dropdown_bg" => [1.0, 1.0, 1.0, 0.98],
		"dropdown_hover" => [0.94, 0.96, 1.0],
		"info_bg" => [0.85, 0.9, 1.0],
		"subtab" => [0.85, 0.88, 0.92],
		"pill_bg" => [0.9, 0.92, 0.95],
		"pill_active" => [0.0, 0.5, 0.8, 0.2],
		"scrollbar" => [0.7, 0.72, 0.75, 0.5],
		"scrollbar_hover" => [0.6, 0.62, 0.65, 0.7],
		"overlay_bg" => [0.94, 0.95, 0.98, 0.95],
	];
	private $titleCloseHover = false;
	private $titleMinHover = false;
	private $titleDragHover = false;

	// UI Colors (Moon Theme: White Cyan)
	private $colors;

	public function __construct($configPath = "FoxyClient/config/mods.json")
	{
		$this->initLogs();
		$this->log("FoxyClient " . self::VERSION . " Starting...");
		$this->log("Environment: PHP " . PHP_VERSION . " on " . PHP_OS);
		$this->log("Working Directory: " . getcwd());

		$this->pollEvents = new \parallel\Events();
		$this->pollEvents->setBlocking(false);
		$this->httpResultChannel = new \parallel\Channel(\parallel\Channel::Infinite);
		$this->pollEvents->addChannel($this->httpResultChannel);
		
		// Initialize persistent HTTP worker
		$this->httpQueueChannel = new \parallel\Channel(\parallel\Channel::Infinite);
		$this->httpWorkerProcess = new \parallel\Runtime();
		
		$qChan = $this->httpQueueChannel;
		$rChan = $this->httpResultChannel;
		$cacert = __DIR__ . DIRECTORY_SEPARATOR . self::CACERT;
		$version = self::VERSION;
		$acc_elyby = self::ACC_ELYBY;
		$acc_foxy = self::ACC_FOXY;
		$acc_offline = self::ACC_OFFLINE;
		$acc_mojang = self::ACC_MOJANG;

		$this->httpWorkerProcess->run(function(\parallel\Channel $q, \parallel\Channel $r, $cacert, $version, $acc_elyby, $acc_foxy, $acc_offline, $acc_mojang) {
			$log = function($msg, $lvl = "INFO") use ($r) {
				$r->send(["type" => "log", "msg" => "[HTTPWorker] " . $msg, "level" => $lvl]);
			};

			$fetch = function($url) use ($version, $cacert, $log) {
				$log("Fetching: $url");
				$ch = curl_init($url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_USERAGENT, "FoxyClient/" . $version);
				curl_setopt($ch, CURLOPT_TIMEOUT, 30);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
				if (file_exists($cacert)) {
					curl_setopt($ch, CURLOPT_CAINFO, $cacert);
				}
				$data = curl_exec($ch);
				$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$curlErr = curl_error($ch);
				curl_close($ch);
				
				if ($data === false) {
					$log("Fetch failed: $url ($curlErr)", "ERROR");
				} else {
					$log("Fetch finished: $url (HTTP $code)");
				}
				return [$data, $code];
			};

			while ($job = $q->recv()) {
				try {
					$type = $job['type'] ?? 'generic';
					$id = $job['id'];
					$log("Received job: $type (ID: $id)");
					
					if ($type === 'skin_resolve') {
						$username = $job['username'];
						$accType = $job['accType'];
						$skinPath = $job['path'];
						$skinUrl = "";
						
						if ($accType === $acc_elyby) {
							[$json] = $fetch("http://skinsystem.ely.by/textures/" . urlencode($username));
							if ($json) {
								$data = json_decode($json, true);
								if (isset($data["SKIN"]["url"])) {
									$skinUrl = $data["SKIN"]["url"];
									$log("Resolved Ely.by skin: $skinUrl");
								}
							}
						} elseif ($accType === $acc_foxy) {
							[$uuidJson] = $fetch("https://foxyclient.qzz.io/api/profiles/minecraft/byname/" . urlencode($username));
							if ($uuidJson) {
								$uuidData = json_decode($uuidJson, true);
								if (isset($uuidData["id"])) {
									$uuid = $uuidData["id"];
									[$profileJson] = $fetch("https://foxyclient.qzz.io/api/sessionserver/session/minecraft/profile/" . $uuid);
									if ($profileJson) {
										$profileData = json_decode($profileJson, true);
										if (isset($profileData["properties"])) {
											foreach ($profileData["properties"] as $prop) {
												if ($prop["name"] === "textures") {
													$texData = json_decode(base64_decode($prop["value"]), true);
													if (isset($texData["textures"]["SKIN"]["url"])) {
														$skinUrl = $texData["textures"]["SKIN"]["url"];
														$log("Resolved FoxyClient skin: $skinUrl");
													}
												}
											}
										}
									}
								}
							}
						} elseif ($accType === $acc_mojang) {
							[$uuidJson] = $fetch("https://api.mojang.com/users/profiles/minecraft/" . urlencode($username));
							if ($uuidJson) {
								$uuidData = json_decode($uuidJson, true);
								if (isset($uuidData["id"])) {
									$uuid = $uuidData["id"];
									[$profileJson] = $fetch("https://sessionserver.mojang.com/session/minecraft/profile/" . $uuid . "?unsigned=false");
									if ($profileJson) {
										$profileData = json_decode($profileJson, true);
										if (isset($profileData["properties"])) {
											foreach ($profileData["properties"] as $prop) {
												if ($prop["name"] === "textures") {
													$texData = json_decode(base64_decode($prop["value"]), true);
													if (isset($texData["textures"]["SKIN"]["url"])) {
														$skinUrl = $texData["textures"]["SKIN"]["url"];
														$log("Resolved Mojang skin: $skinUrl");
													}
												}
											}
										}
									}
								}
							}
						}

						if (empty($skinUrl)) {
							$skinUrl = "https://minotar.net/skin/" . urlencode($username) . ".png";
							$log("Falling back to Minotar skin: $skinUrl");
						}

						[$skinData, $code] = $fetch($skinUrl);
						if ($skinData && $code === 200) {
							file_put_contents($skinPath, $skinData);
							$r->send(["type" => "http_result", "id" => $id, "success" => true, "path" => $skinPath]);
						} else {
							$r->send(["type" => "http_result", "id" => $id, "success" => false, "error" => "Download failed for $skinUrl"]);
						}
					} else {
						// Generic download or fetch
						$url = $job['url'];
						$path = $job['path'] ?? null;
						[$data, $code] = $fetch($url);
						if ($path && $data !== false && $code === 200) {
							file_put_contents($path, $data);
						}
						$r->send([
							"type" => "http_result",
							"id" => $id,
							"success" => ($data !== false),
							"data" => $data,
							"code" => $code,
							"path" => $path,
							"metadata" => $job['metadata'] ?? []
						]);
					}
				} catch (\Throwable $e) {
					$r->send(["type" => "http_result", "id" => $job['id'] ?? 'unknown', "success" => false, "error" => $e->getMessage()]);
				}
			}
		}, [$this->httpQueueChannel, $this->httpResultChannel, $cacert, $version, $acc_elyby, $acc_foxy, $acc_offline, $acc_mojang]);
		
		$this->colors = $this->darkColors; // Default initial colors to prevent null access
		$this->loadConfig($configPath);
		$this->loadSettings();
		$this->loadModpacks();
		$this->loadFoxyKeybinds();
		$this->loadFoxyMacros();
		$this->loadFoxyConfig();
		$this->checkLocalMods();
		$this->applyTheme();
		$this->initFFI();
		$this->initGDIPlus();
		$this->initMicrosoftLogin();
		$this->createWindow();
		$this->initGL();
		$this->loadLogo();
		$this->loadBackground();

		// Load version cache first
		$cacheFile = self::CACHE_DIR . DIRECTORY_SEPARATOR . "versions_cache.json";
		if (file_exists($cacheFile)) {
			$cacheData = json_decode(file_get_contents($cacheFile), true);
			if ($cacheData && isset($cacheData["versions"])) {
				$this->versions = $cacheData["versions"];
				$this->versionsLoaded = true;
				$this->log(
					"Loaded versions cache: " .
						count($this->versions) .
						" versions found.",
				);
			}
		}
		$this->loadVersions(); // Background update

		$this->appLaunchTime = microtime(true);
		$this->lastTime = $this->appLaunchTime;

		// Discord RPC Init
		$this->discord = new DiscordRPC();
		$this->discord->init("1475364971180331109");
		$this->updateDiscordPresence();

		// Silent background update check
		$this->triggerCheckForUpdate(true);
	}

	private function loadConfig($path)
	{
		if (!is_dir(self::DATA_DIR)) {
			mkdir(self::DATA_DIR, 0777, true);
			$this->log("Created data directory: " . self::DATA_DIR);
		}

		if (!file_exists($path)) {
			// Create default config if missing
			$default = [
				"minecraft_version" => "1.21.1",
				"loader" => "fabric",
				"game_optimize_mods" => [],
				"additional_mods" => [],
			];
			file_put_contents($path, json_encode($default, JSON_PRETTY_PRINT));
		}
		$this->config = json_decode(file_get_contents($path), true);
		$this->log("Launcher Configuration loaded: " . realpath($path));

		$this->loadAccounts();

		if ($this->isLoggedIn) {
			$this->currentPage = self::PAGE_HOME;
		} else {
			$this->currentPage = self::PAGE_LOGIN;
		}

		// Set selected version if present
		if (isset($this->config["minecraft_version"])) {
			$this->selectedVersion = $this->config["minecraft_version"];
		}

		// Tab 0: Optimization mods
		$optMods = [];
		foreach ($this->config["game_optimize_mods"] as $id) {
			$optMods[] = ["id" => $id, "checked" => true, "status" => "idle"];
		}
		$this->tabs[] = ["name" => "Optimization", "mods" => $optMods];

		// Additional packs as separate tabs
		foreach ($this->config["additional_mods"] as $pack => $mods) {
			$packMods = [];
			foreach ($mods as $id) {
				$packMods[] = [
					"id" => $id,
					"checked" => false,
					"status" => "idle",
				];
			}
			$this->tabs[] = ["name" => $pack, "mods" => $packMods];
		}
	}

	private function checkLocalMods()
	{
		$modsDir =
			$this->getAbsolutePath($this->settings["game_dir"]) .
			DIRECTORY_SEPARATOR .
			"mods";
		if (!is_dir($modsDir)) {
			return;
		}

		$files = scandir($modsDir);
		$localFilenames = [];
		foreach ($files as $f) {
			if ($f === "." || $f === "..") {
				continue;
			}
			if (str_ends_with(strtolower($f), ".jar")) {
				$localFilenames[] = strtolower($f);
			}
		}

		foreach ($this->tabs as &$tab) {
			foreach ($tab["mods"] as &$mod) {
				if ($mod["status"] !== "idle") {
					continue;
				}

				$id = strtolower($mod["id"]);
				foreach ($localFilenames as $fname) {
					// Check if filename starts with mod ID or ID is surrounded by dividers
					// e.g. "sodium-fabric-mc1.21.1.jar" matches "sodium"
					if (
						$fname === "$id.jar" ||
						str_starts_with($fname, "$id-") ||
						str_starts_with($fname, "{$id}_")
					) {
						$mod["status"] = "ok";
						break;
					}
				}
			}
		}
	}

	private function initFFI()
	{
		$types = "
			typedef unsigned int UINT;
			typedef unsigned long DWORD;
			typedef void* HWND;
			typedef void* HDC;
			typedef void* HGLRC;
			typedef void* HINSTANCE;
			typedef void* HMENU;
			typedef void* LPVOID;
			typedef void* PVOID;
			typedef char* LPCSTR;
			typedef long long LRESULT;
			typedef unsigned long long WPARAM;
			typedef long long LPARAM;
			typedef void* HBRUSH;
			typedef void* HICON;
			typedef void* HCURSOR;
			typedef int BOOL;
			typedef void* HGDIOBJ;
			typedef void* HFONT;
			typedef unsigned short wchar_t;
			typedef unsigned int UINT32;

			typedef struct {
				unsigned char data[16];
			} GUID;

			// COM IUnknown Interface
			typedef struct IUnknown IUnknown;
			typedef struct IUnknownVtbl {
				void* QueryInterface;
				UINT (*AddRef)(IUnknown*);
				UINT (*Release)(IUnknown*);
			} IUnknownVtbl;
			struct IUnknown {
				IUnknownVtbl* lpVtbl;
			};

			typedef struct {
				UINT32 cbSize;
				long long rateCompose;
				long long qpcVBlank;
				long long cRefresh;
				long long qpcCompose;
				long long cFrame;
				long long cRefreshFrame;
				long long cRefreshConfirmed;
				long long qpcRefreshConfirmed;
				long long cRefreshPaused;
				long long qpcRefreshPaused;
				long long cRefreshPageFault;
				long long qpcRefreshPageFault;
				long long cRefreshMissed;
				long long qpcRefreshMissed;
				long long cRefreshSystem;
				long long qpcRefreshSystem;
			} DWM_TIMING_INFO;

			typedef LRESULT (*WNDPROC)(HWND, UINT, WPARAM, LPARAM);

			typedef struct {
				UINT	  style;
				WNDPROC   lpfnWndProc;
				int	   cbClsExtra;
				int	   cbWndExtra;
				HINSTANCE hInstance;
				HICON	 hIcon;
				HCURSOR   hCursor;
				HBRUSH	hbrBackground;
				LPCSTR	lpszMenuName;
				LPCSTR	lpszClassName;
			} WNDCLASSA;

			typedef struct {
				HWND	 hwnd;
				UINT	 message;
				WPARAM   wParam;
				LPARAM   lParam;
				DWORD	time;
				int	  pt_x;
				int	  pt_y;
				DWORD	lPrivate;
			} MSG;

			typedef struct {
				short nSize;
				short nVersion;
				DWORD dwFlags;
				unsigned char iPixelType;
				unsigned char cColorBits;
				unsigned char cRedBits;
				unsigned char cRedShift;
				unsigned char cGreenBits;
				unsigned char cGreenShift;
				unsigned char iAlphaBits;
				unsigned char iAlphaShift;
				unsigned char cAccumBits;
				unsigned char cAccumRedBits;
				unsigned char cAccumGreenBits;
				unsigned char cAccumBlueBits;
				unsigned char cAccumAlphaBits;
				unsigned char cDepthBits;
				unsigned char cStencilBits;
				unsigned char cAuxBuffers;
				unsigned char iLayerType;
				unsigned char bReserved;
				DWORD dwLayerMask;
				DWORD dwVisibleMask;
				DWORD dwDamageMask;
			} PIXELFORMATDESCRIPTOR;

			typedef struct {
				long left;
				long top;
				long right;
				long bottom;
			} RECT;

			typedef struct {
				DWORD biSize;
				long biWidth;
				long biHeight;
				unsigned short biPlanes;
				unsigned short biBitCount;
				DWORD biCompression;
				DWORD biSizeImage;
				long biXPelsPerMeter;
				long biYPelsPerMeter;
				DWORD biClrUsed;
				DWORD biClrImportant;
			} BITMAPINFOHEADER;

			typedef struct {
				BITMAPINFOHEADER bmiHeader;
			} BITMAPINFO;

			typedef struct {
				long cx;
				long cy;
			} SIZE;

			typedef struct {
				UINT version;
				LPVOID debugCallback;
				BOOL suppressBackgroundThread;
				BOOL suppressExternalCodecs;
			} GdiplusStartupInput;

			typedef struct {
				UINT width;
				UINT height;
				int stride;
				int pixelFormat;
				void* scan0;
				UINT* reserved;
			} BitmapData;

			typedef struct {
				DWORD dwLength;
				DWORD dwMemoryLoad;
				unsigned long long ullTotalPhys;
				unsigned long long ullAvailPhys;
				unsigned long long ullTotalPageFile;
				unsigned long long ullAvailPageFile;
				unsigned long long ullTotalVirtual;
				unsigned long long ullAvailVirtual;
				unsigned long long ullAvailExtendedVirtual;
			} MEMORYSTATUSEX;

			typedef int (*BFFCALLBACK)(HWND, UINT, LPARAM, LPARAM);
			typedef int (*PFNWGLSWAPINTERVALEXTPROC)(int interval);

			typedef struct {
				HWND hwndOwner;
				void* pidlRoot;
				char* pszDisplayName;
				char* lpszTitle;
				UINT ulFlags;
				BFFCALLBACK lpfn;
				LPARAM lParam;
				int iImage;
			} BROWSEINFOA;
		";

		$this->kernel32 = FFI::cdef(
			$types .
				"
			HINSTANCE GetModuleHandleA(LPCSTR lpModuleName);
			DWORD GetTickCount();
			BOOL QueryPerformanceCounter(long long *lpPerformanceCount);
			BOOL QueryPerformanceFrequency(long long *lpFrequency);
			BOOL GetSystemTimes(long long *lpIdleTime, long long *lpKernelTime, long long *lpUserTime);
			BOOL GlobalMemoryStatusEx(MEMORYSTATUSEX *lpBuffer);
			PVOID GlobalAlloc(UINT uFlags, size_t dwBytes);
			PVOID GlobalLock(PVOID hMem);
			BOOL GlobalUnlock(PVOID hMem);
			PVOID GlobalFree(PVOID hMem);
			void *GetCurrentProcess();
			BOOL TerminateProcess(void *hProcess, UINT uExitCode);
		",
			"kernel32.dll",
		);

		$this->user32 = FFI::cdef(
			$types .
				"
			HWND CreateWindowExA(DWORD dwExStyle, LPCSTR lpClassName, LPCSTR lpWindowName, DWORD dwStyle, int X, int Y, int nWidth, int nHeight, HWND hWndParent, HMENU hMenu, HINSTANCE hInstance, LPVOID lpParam);
			int MessageBoxA(HWND hWnd, LPCSTR lpText, LPCSTR lpCaption, UINT uType);
			HDC GetDC(HWND hWnd);
			int ReleaseDC(HWND hWnd, HDC hDC);
			BOOL DestroyWindow(HWND hWnd);
			void PostQuitMessage(int nExitCode);
			LRESULT DefWindowProcA(HWND hWnd, UINT Msg, WPARAM wParam, LPARAM lParam);
			UINT RegisterClassA(const WNDCLASSA *lpWndClass);
			BOOL ShowWindow(HWND hWnd, int nCmdShow);
			BOOL UpdateWindow(HWND hWnd);
			BOOL PeekMessageA(MSG *lpMsg, HWND hWnd, UINT wMsgFilterMin, UINT wMsgFilterMax, UINT wRemoveMsg);
			BOOL TranslateMessage(const MSG *lpMsg);
			LRESULT DispatchMessageA(const MSG *lpMsg);
			BOOL AdjustWindowRectEx(RECT *lpRect, DWORD dwStyle, BOOL bMenu, DWORD dwExStyle);
			BOOL ReleaseCapture();
			HWND GetForegroundWindow();
			BOOL IsIconic(HWND hWnd);
			LRESULT SendMessageA(HWND hWnd, UINT Msg, WPARAM wParam, void* lParam);
			BOOL SetWindowPos(HWND hWnd, HWND hWndInsertAfter, int X, int Y, int cx, int cy, UINT uFlags);
			LRESULT SendMessageW(HWND hWnd, UINT Msg, WPARAM wParam, void* lParam);
			HCURSOR LoadCursorA(HINSTANCE hInstance, LPCSTR lpCursorName);
			BOOL WaitMessage();
			BOOL GetWindowRect(HWND hWnd, RECT *lpRect);
			DWORD GetWindowThreadProcessId(HWND hWnd, DWORD *lpdwProcessId);
			BOOL EnumWindows(BOOL (*lpEnumFunc)(HWND, LPARAM), LPARAM lParam);
			long long GetWindowLongPtrA(HWND hWnd, int nIndex);
			long long SetWindowLongPtrA(HWND hWnd, int nIndex, long long dwNewLong);
			BOOL SetLayeredWindowAttributes(HWND hwnd, DWORD crKey, unsigned char bAlpha, DWORD dwFlags);
			BOOL GetClientRect(HWND hWnd, RECT *lpRect);
			BOOL ClientToScreen(HWND hWnd, LPVOID lpPoint);
			int FillRect(HDC hDC, const RECT *lprc, HBRUSH hbr);
			int GetWindowTextA(HWND hWnd, char *lpString, int nMaxCount);
			BOOL IsWindow(HWND hWnd);
			BOOL IsWindowVisible(HWND hWnd);
			HWND SetParent(HWND hWndChild, HWND hWndNewParent);
			BOOL OpenClipboard(HWND hWndNewOwner);
			BOOL CloseClipboard();
			BOOL EmptyClipboard();
			HWND GetClipboardOwner();
			PVOID GetClipboardData(UINT uFormat);
			PVOID SetClipboardData(UINT uFormat, PVOID hMem);
			short GetKeyState(int nVirtKey);
		",
			"user32.dll",
		);

		try {
			$this->dwmapi = FFI::cdef(
				$types .
					"
				int DwmGetCompositionTimingInfo(HWND hwnd, DWM_TIMING_INFO *pTimingInfo);
				long DwmFlush();
			",
				"dwmapi.dll",
			);
		} catch (\Exception $e) {
		}

		$this->gdi32 = FFI::cdef(
			$types .
				"
			HGDIOBJ SelectObject(HDC hdc, HGDIOBJ h);
			HGDIOBJ GetStockObject(int i);
			HFONT CreateFontA(int cHeight, int cWidth, int nEscapement, int nOrientation, int cWeight, DWORD bItalic, DWORD bUnderline, DWORD bStrikeOut, DWORD iCharSet, DWORD iOutPrecision, DWORD iClipPrecision, DWORD iQuality, DWORD iPitchAndFamily, LPCSTR pszFaceName);
			int ChoosePixelFormat(HDC hdc, const PIXELFORMATDESCRIPTOR *ppfd);
			BOOL SetPixelFormat(HDC hdc, int format, const PIXELFORMATDESCRIPTOR *ppfd);
			BOOL SwapBuffers(HDC hdc);
			int AddFontResourceExA(LPCSTR name, DWORD fl, PVOID res);
			BOOL RemoveFontResourceExA(LPCSTR name, DWORD fl, PVOID res);
			HDC CreateCompatibleDC(HDC hdc);
			void* CreateDIBSection(HDC hdc, const BITMAPINFO *pbmi, UINT usage, void **ppvBits, void *hSection, DWORD offset);
			int SetBkMode(HDC hdc, int mode);
			DWORD SetBkColor(HDC hdc, DWORD color);
			DWORD SetTextColor(HDC hdc, DWORD color);
			BOOL TextOutA(HDC hdc, int x, int y, LPCSTR lpString, int c);
			BOOL GetTextExtentPoint32A(HDC hdc, LPCSTR lpString, int c, SIZE *psizl);
			BOOL DeleteDC(HDC hdc);
			BOOL DeleteObject(void *ho);
		",
			"gdi32.dll",
		);

		$this->opengl32 = FFI::cdef(
			$types .
				"
			HGLRC wglCreateContext(HDC hdc);
			BOOL wglMakeCurrent(HDC hdc, HGLRC hglrc);
			BOOL wglDeleteContext(HGLRC hglrc);
			void* wglGetProcAddress(LPCSTR);

			void glClear(UINT mask);
			void glClearColor(float red, float green, float blue, float alpha);
			void glViewport(int x, int y, int width, int height);
			void glMatrixMode(UINT mode);
			void glLoadIdentity();
			void glOrtho(double left, double right, double bottom, double top, double zNear, double zFar);
			void glBegin(UINT mode);
			void glEnd();
			void glVertex2f(float x, float y);
			void glColor3f(float red, float green, float blue);
			void glColor4f(float red, float green, float blue, float alpha);
			void glEnable(UINT cap);
			void glDisable(UINT cap);
			void glLineWidth(float width);
			void glBlendFunc(UINT sfactor, UINT dfactor);
			void glScissor(int x, int y, int width, int height);
			void glGenTextures(int n, UINT *textures);
			void glDeleteTextures(int n, const UINT *textures);
			void glBindTexture(UINT target, UINT texture);
			void glTexImage2D(UINT target, int level, int internalformat, int width, int height, int border, UINT format, UINT type, const void *pixels);
			void glTexParameteri(UINT target, UINT pname, int param);
			void glTexCoord2f(float s, float t);
			void glPushMatrix();
			void glPopMatrix();
			void glTranslatef(float x, float y, float z);
			void glScalef(float x, float y, float z);
			void glRotatef(float angle, float x, float y, float z);
			void glVertex3f(float x, float y, float z);
			void glNormal3f(float nx, float ny, float nz);
			void glClearDepth(double depth);
			void glDepthFunc(UINT func);
			void glCullFace(UINT mode);
			void glFrustum(double left, double right, double bottom, double top, double zNear, double zFar);
		",
			"opengl32.dll",
		);

		$this->gdiplus = FFI::cdef(
			$types .
				"
			int GdiplusStartup(unsigned long long *token, const GdiplusStartupInput *input, void *output);
			void GdiplusShutdown(unsigned long long token);
			int GdipCreateBitmapFromFile(const wchar_t *filename, void **bitmap);
			int GdipCreateBitmapFromStream(void *stream, void **bitmap);
			int GdipDisposeImage(void *image);
			int GdipGetImageWidth(void *image, UINT *width);
			int GdipGetImageHeight(void *image, UINT *height);
			int GdipBitmapLockBits(void *bitmap, const void *rect, UINT flags, int format, BitmapData *lockedBitmapData);
			int GdipBitmapUnlockBits(void *bitmap, BitmapData *lockedBitmapData);
			int GdipCreateHICONFromBitmap(void *bitmap, HICON *hicon);
			
			// GDI+ 1.1 Effects
			int GdipCreateEffect(const GUID *guid, void **effect);
			int GdipDeleteEffect(void *effect);
			int GdipSetEffectParameters(void *effect, const void *params, UINT size);
			int GdipBitmapApplyEffect(void *bitmap, void *effect, const void *rect, BOOL useAuxData, void **auxData, void **auxDataRect);

			// GDI+ Fallback (Scaling/Interpolation)
			int GdipGetImageGraphicsContext(void *image, void **graphics);
			int GdipDeleteGraphics(void *graphics);
			int GdipSetInterpolationMode(void *graphics, int mode);
			int GdipDrawImageRectRectI(void *graphics, void *image, int dstx, int dsty, int dstwidth, int dstheight, int srcx, int srcy, int srcwidth, int srcheight, int srcunit, void *imageAttributes, void *callback, void *callbackData);
			int GdipCreateBitmapFromScan0(int width, int height, int stride, int format, void *scan0, void **bitmap);
		",
			"gdiplus.dll",
		);

		$this->comdlg32 = FFI::cdef(
			$types .
				"
			typedef struct {
				DWORD		lStructSize;
				HWND		 hwndOwner;
				HINSTANCE	hInstance;
				LPCSTR	   lpstrFilter;
				LPCSTR	   lpstrCustomFilter;
				DWORD		nMaxCustFilter;
				DWORD		nFilterIndex;
				char*		lpstrFile;
				DWORD		nMaxFile;
				char*		lpstrFileTitle;
				DWORD		nMaxFileTitle;
				LPCSTR	   lpstrInitialDir;
				LPCSTR	   lpstrTitle;
				DWORD		Flags;
				unsigned short nFileOffset;
				unsigned short nFileExtension;
				LPCSTR	   lpstrDefExt;
				LPARAM	   lCustData;
				void*		lpfnHook;
				LPCSTR	   lpTemplateName;
				void*		pvReserved;
				DWORD		dwReserved;
				DWORD		FlagsEx;
			} OPENFILENAMEA;

			BOOL GetOpenFileNameA(OPENFILENAMEA *lpofn);
		",
			"comdlg32.dll",
		);

		$this->shell32 = FFI::cdef(
			$types .
				"
			void* SHBrowseForFolderA(BROWSEINFOA *lpbi);
			BOOL SHGetPathFromIDListA(void* pidl, char* pszPath);
			void* ShellExecuteA(void* hwnd, const char* lpOperation, const char* lpFile, const char* lpParameters, const char* lpDirectory, int nShowCmd);
		",
			"shell32.dll",
		);

		$this->ole32 = FFI::cdef(
			$types .
				"
			int CreateStreamOnHGlobal(PVOID hGlobal, BOOL fDeleteOnRelease, IUnknown **ppstm);
		",
			"ole32.dll",
		);
	}

	private function createWindow()
	{
		$inst = $this->kernel32->GetModuleHandleA(null);
		$className = "FoxyClientClass";

		$wc = $this->user32->new("WNDCLASSA");
		$wc->style = 0x0003;
		$wc->hCursor = $this->user32->LoadCursorA(
			null,
			$this->user32->cast("LPCSTR", 32512),
		); // IDC_ARROW = 32512
		$this->wndProc = function ($hwnd, $msg, $wparam, $lparam) {
			try {
				switch ($msg) {
					case 0x0002: // WM_DESTROY
						$this->terminateGame();
						$this->user32->PostQuitMessage(0);
						$this->running = false;
						return 0;
					case 0x0201: // WM_LBUTTONDOWN
						$this->needsRedraw = true;
						$this->handleMouseClick(
							$lparam & 0xffff,
							($lparam >> 16) & 0xffff,
						);
						return 0;
					case 0x0202: // WM_LBUTTONUP
						$this->needsRedraw = true;
						$this->isDraggingScroll = false;
						return 0;
					case 0x0200: // WM_MOUSEMOVE
						$this->needsRedraw = true;
						$this->mouseX = $lparam & 0xffff;
						$this->mouseY = ($lparam >> 16) & 0xffff;
						$this->handleMouseMove($this->mouseX, $this->mouseY);
						return 0;
					case 0x020a: // WM_MOUSEWHEEL
						$this->needsRedraw = true;
						$wHigh = ($wparam >> 16) & 0xffff;
						$delta = $wHigh > 0x7fff ? $wHigh - 0x10000 : $wHigh;

						if ($this->currentPage === self::PAGE_MODS) {
							if ($this->modsFilterDropdown !== "") {
								$this->modsFilterScrollTarget -= $delta / 3;
								// Clamping happens in renderModsFilterDropdown
							} else {
								$this->scrollTarget -= $delta / 3;
								$maxScroll = $this->getMaxScroll();
								if ($this->scrollTarget < 0) {
									$this->scrollTarget = 0;
								}
								if ($this->scrollTarget > $maxScroll) {
									$this->scrollTarget = $maxScroll;
								}
							}
						} elseif ($this->currentPage === self::PAGE_VERSIONS) {
							$this->vScrollTarget -= $delta / 3;
							$maxScroll = $this->getMaxVersionScroll();
							if ($this->vScrollTarget < 0) {
								$this->vScrollTarget = 0;
							}
							if ($this->vScrollTarget > $maxScroll) {
								$this->vScrollTarget = $maxScroll;
							}
						} elseif (
							$this->currentPage === self::PAGE_PROPERTIES
						) {
							$this->propScrollTarget -= $delta / 3;
							if ($this->propScrollTarget < 0) {
								$this->propScrollTarget = 0;
							}
							if ($this->propScrollTarget > 200) {
								$this->propScrollTarget = 200;
							}
						} elseif (
							$this->currentPage === self::PAGE_FOXYCLIENT
						) {
							if ($this->foxySubTab === 0) {
								$this->scrollTarget -= $delta / 3;
								$maxScroll = $this->getMaxScroll();
								if ($this->scrollTarget < 0) $this->scrollTarget = 0;
								if ($this->scrollTarget > $maxScroll) $this->scrollTarget = $maxScroll;
							} elseif ($this->foxySubTab === 1) {
								$this->foxyKeybindScrollTarget -= $delta / 3;
								$contentH = count($this->foxyKeybindData) * 40;
								$listH = $this->height - self::TITLEBAR_H - self::FOOTER_H - (self::HEADER_H + self::TAB_H + 38);
								$maxScroll = max(0, $contentH - $listH);
								if ($this->foxyKeybindScrollTarget < 0) $this->foxyKeybindScrollTarget = 0;
								if ($this->foxyKeybindScrollTarget > $maxScroll) $this->foxyKeybindScrollTarget = $maxScroll;
							} elseif ($this->foxySubTab === 2) {
								$this->foxyMacroScrollTarget -= $delta / 3;
								$contentH = count($this->foxyMacroData) * 44;
								$listH = $this->height - self::TITLEBAR_H - self::FOOTER_H - (self::HEADER_H + self::TAB_H + 38) - 40;
								$maxScroll = max(0, $contentH - $listH);
								if ($this->foxyMacroScrollTarget < 0) $this->foxyMacroScrollTarget = 0;
								if ($this->foxyMacroScrollTarget > $maxScroll) $this->foxyMacroScrollTarget = $maxScroll;
							} elseif ($this->foxySubTab === 3) {
								$this->foxyConfigScrollTarget -= $delta / 3;
								$hiddenKeys = ["skinName", "capeName", "slimModel", "customMusicName", "customFontName", "customBackgroundName", "customSkinPath", "customFontPath", "customBackgroundPath", "customMusicPath"];
								$keys = array_values(array_filter(array_keys($this->foxyConfigData), function($k) use ($hiddenKeys) {
									return !in_array($k, $hiddenKeys);
								}));
								$contentRows = ceil(count($keys) / 2);
								$contentH = $contentRows * (70 + 15) + 10;
								$listH = $this->height - self::TITLEBAR_H - self::FOOTER_H - (self::HEADER_H + self::TAB_H + 10);
								$maxScroll = max(0, $contentH - $listH);
								if ($this->foxyConfigScrollTarget < 0) $this->foxyConfigScrollTarget = 0;
								if ($this->foxyConfigScrollTarget > $maxScroll) $this->foxyConfigScrollTarget = $maxScroll;
							} elseif ($this->foxySubTab === 4) {
								$this->foxyPreviewZoom += ($delta > 0 ? 0.1 : -0.1);
								if ($this->foxyPreviewZoom < 0.2) $this->foxyPreviewZoom = 0.2;
								if ($this->foxyPreviewZoom > 5.0) $this->foxyPreviewZoom = 5.0;
							}
						} elseif ($this->currentPage === self::PAGE_ACCOUNTS) {
							$this->accScrollTarget -= $delta / 3;
							$contentH = count($this->accounts) * 70;
							$listH = $this->height - self::TITLEBAR_H - self::FOOTER_H - 110;
							$maxScroll = max(0, $contentH - $listH);
							if ($this->accScrollTarget < 0) $this->accScrollTarget = 0;
							if ($this->accScrollTarget > $maxScroll) $this->accScrollTarget = $maxScroll;
						} elseif ($this->currentPage === self::PAGE_HOME) {
							if ($this->homeVerDropdownOpen) {
								$this->homeVerScrollTarget -= $delta / 3;
								$contentH =
									count($this->getHomeVersions()) * 40;
								$viewH = 200;
								$maxScroll = max(0, $contentH - $viewH);
								if ($this->homeVerScrollTarget < 0) {
									$this->homeVerScrollTarget = 0;
								}
								if ($this->homeVerScrollTarget > $maxScroll) {
									$this->homeVerScrollTarget = $maxScroll;
								}
							}
						}
						return 0;
					case 0x0100: // WM_KEYDOWN
						// Keybind listen mode: capture the pressed key
						if ($this->foxyKeybindListenMode && $this->foxyKeybindEditIdx >= 0) {
							$this->needsRedraw = true;
							$filteredKeys = [];
							foreach (array_keys($this->foxyKeybindData) as $k) {
								if ($this->foxyKeybindSearchQuery === "" || stripos($k, $this->foxyKeybindSearchQuery) !== false) {
									$filteredKeys[] = $k;
								}
							}
							if ($this->foxyKeybindEditIdx < count($filteredKeys)) {
								$moduleName = $filteredKeys[$this->foxyKeybindEditIdx];
								if ($wparam === 0x1B) { // VK_ESCAPE = unbind
									$this->foxyKeybindData[$moduleName]["keybind"] = -1;
								} else {
									$glfwKey = $this->vkToGlfw($wparam);
									$this->foxyKeybindData[$moduleName]["keybind"] = $glfwKey;
								}
								$this->saveFoxyKeybinds();
							}
							$this->foxyKeybindListenMode = false;
							$this->foxyKeybindEditIdx = -1;
							return 0;
						}
						// Macro listen mode: reassign macro to new key
						if ($this->foxyMacroListenMode && $this->foxyMacroEditIdx >= 0) {
							$this->needsRedraw = true;
							$keys = array_keys($this->foxyMacroData);
							if ($this->foxyMacroEditIdx < count($keys)) {
								$oldKey = $keys[$this->foxyMacroEditIdx];
								$command = $this->foxyMacroData[$oldKey];
								if ($wparam !== 0x1B) { // Not ESC = rebind
									$newKey = (string) $this->vkToGlfw($wparam);
									unset($this->foxyMacroData[$oldKey]);
									$this->foxyMacroData[$newKey] = $command;
									$this->saveFoxyMacros();
								}
								// ESC just cancels
							}
							$this->foxyMacroListenMode = false;
							$this->foxyMacroEditIdx = -1;
							return 0;
						}
						if ($this->handleClipboardInput($wparam)) {
							return 0;
						}
						return 0;
					case 0x0102: // WM_CHAR
						$this->needsRedraw = true;
						if (
							$this->currentPage === self::PAGE_MODS &&
							$this->modSearchFocus
						) {
							$char = $wparam;
							if ($char == 8) {
								// Backspace
								$this->modSearchQuery = (string) substr(
									$this->modSearchQuery,
									0,
									-1,
								);
								$this->modSearchDebounceTimer =
									microtime(true) + 0.4;
							} elseif ($char == 13) {
								// Enter
								$this->modSearchFocus = false;
								$this->modSearchDebounceTimer = 0; // Trigger immediately
								$this->searchModrinth($this->modSearchQuery);
							} elseif ($char >= 32) {
								$this->modSearchQuery .= chr($char);
								$this->modSearchDebounceTimer =
									microtime(true) + 0.4;
							}
						} elseif (
							$this->currentPage === self::PAGE_LOGIN &&
							$this->inputFocus
						) {
							$char = $wparam;
							if ($char == 8) {
								// Backspace
								if (
									$this->loginStep === 2 &&
									$this->inputFocus === "totp"
								) {
									$this->loginInputTOTP = (string) substr(
										$this->loginInputTOTP,
										0,
										-1,
									);
								} elseif (
									($this->loginType === self::ACC_ELYBY ||
										$this->loginType === self::ACC_FOXY) &&
									!empty($this->loginInputPassword) &&
									$this->loginStep === 1 &&
									$this->inputFocus === "password"
								) {
									$this->loginInputPassword = (string) substr(
										$this->loginInputPassword,
										0,
										-1,
									);
								} else {
									$this->loginInput = (string) substr(
										$this->loginInput,
										0,
										-1,
									);
								}
							} elseif ($char >= 32) {
								if (
									$this->loginStep === 2 &&
									$this->inputFocus === "totp"
								) {
									if (
										strlen($this->loginInputTOTP) < 6 &&
										$char >= 48 &&
										$char <= 57
									) {
										$this->loginInputTOTP .= chr($char);
									}
								} elseif (
									($this->loginType === self::ACC_ELYBY ||
										$this->loginType === self::ACC_FOXY) &&
									$this->loginStep === 1 &&
									$this->inputFocus === "password"
								) {
									$this->loginInputPassword .= chr($char);
								} else {
									$this->loginInput .= chr($char);
								}
							}
						} elseif (
							$this->currentPage === self::PAGE_PROPERTIES &&
							$this->propActiveField !== ""
						) {
							$char = $wparam;
							$key = $this->propActiveField;
							if ($char == 8) {
								// Backspace
								$this->settings[$key] = (string) substr(
									$this->settings[$key],
									0,
									-1,
								);
							} elseif ($char == 13) {
								// Enter
								$this->propActiveField = "";
								$this->saveSettings();
							} elseif ($char >= 32) {
								if ($key === "ram_mb") {
									if ($char >= 48 && $char <= 57) {
										$this->settings[$key] .= chr($char);
									}
								} else {
									$this->settings[$key] .= chr($char);
								}
							}
						} elseif (
							$this->javaModalOpen &&
							$this->javaModalActiveField !== ""
						) {
							$char = $wparam;
							$key = $this->javaModalActiveField;
							if ($char == 8) {
								// Backspace
								$this->settings[$key] = (string) substr(
									$this->settings[$key],
									0,
									-1,
								);
							} elseif ($char == 13) {
								// Enter
								$this->javaModalActiveField = "";
								$this->saveSettings();
							} elseif ($char >= 32) {
								$this->settings[$key] .= chr($char);
							}
						} elseif (
							$this->bgModalOpen &&
							$this->bgModalActiveField !== ""
						) {
							$char = $wparam;
							$key = $this->bgModalActiveField;
							if ($char == 8) {
								$this->settings[$key] = (string) substr(
									$this->settings[$key],
									0,
									-1,
								);
							} elseif ($char == 13) {
								$this->bgModalActiveField = "";
								$this->saveSettings();
								$this->loadBackground();
							} elseif ($char >= 32) {
								$this->settings[$key] .= chr($char);
							}
						} elseif (
							$this->currentPage === self::PAGE_FOXYCLIENT &&
							$this->foxySubTab === 1 &&
							$this->foxyKeybindSearchFocus
						) {
							$char = $wparam;
							if ($char == 8) {
								// Backspace
								$this->foxyKeybindSearchQuery = (string) substr(
									$this->foxyKeybindSearchQuery,
									0,
									-1,
								);
								$this->foxyKeybindScrollTarget = 0; // Reset scroll on search change
							} elseif ($char == 13) {
								// Enter
								$this->foxyKeybindSearchFocus = false;
							} elseif ($char >= 32) {
								$this->foxyKeybindSearchQuery .= chr($char);
								$this->foxyKeybindScrollTarget = 0;
							}
						} elseif (
							$this->currentPage === self::PAGE_FOXYCLIENT &&
							$this->foxySubTab === 2 &&
							$this->foxyMacroEditCommandIdx >= 0
						) {
							$char = $wparam;
							$keys = array_keys($this->foxyMacroData);
							if ($this->foxyMacroEditCommandIdx < count($keys)) {
								$key = $keys[$this->foxyMacroEditCommandIdx];
								if ($char == 8) {
									$this->foxyMacroData[$key] = (string) substr($this->foxyMacroData[$key], 0, -1);
									$this->saveFoxyMacros();
								} elseif ($char == 13) {
									$this->foxyMacroEditCommandIdx = -1;
									$this->saveFoxyMacros();
								} elseif ($char >= 32) {
									$this->foxyMacroData[$key] .= chr($char);
									$this->saveFoxyMacros();
								}
							}
						}
						return 0;
				}
			} catch (\Throwable $e) {
				$this->log(
					"Fatal error in wndProc callback: " . $e->getMessage(),
				);
				return $this->user32->DefWindowProcA(
					$hwnd,
					$msg,
					$wparam,
					$lparam,
				);
			}
			return $this->user32->DefWindowProcA($hwnd, $msg, $wparam, $lparam);
		};
		$wc->lpfnWndProc = $this->wndProc;
		$wc->hInstance = $inst;

		$this->wndProcClassName = $this->user32->new(
			"char[" . (strlen($className) + 1) . "]",
			false,
		);
		FFI::memcpy($this->wndProcClassName, $className, strlen($className));
		$wc->lpszClassName = $this->wndProcClassName;

		$this->user32->RegisterClassA(FFI::addr($wc));

		// Create a frameless window (WS_POPUP)
		// WS_POPUP | WS_VISIBLE | WS_CLIPSIBLINGS | WS_CLIPCHILDREN
		$dwStyle = 0x96000000;

		$this->hwnd = $this->user32->CreateWindowExA(
			0,
			$className,
			"Foxy Client",
			$dwStyle,
			100,
			100,
			$this->width,
			$this->height,
			null,
			null,
			$inst,
			null,
		);

		$this->hdc = $this->user32->GetDC($this->hwnd);

		$pfd = $this->gdi32->new("PIXELFORMATDESCRIPTOR");
		$pfd->nSize = FFI::sizeof($pfd);
		$pfd->nVersion = 1;
		$pfd->dwFlags = 0x00000001 | 0x00000004 | 0x00000020; // PFD_DOUBLEBUFFER | PFD_DRAW_TO_WINDOW | PFD_SUPPORT_OPENGL
		$pfd->iPixelType = 0;
		$pfd->cColorBits = 32;
		$pfd->cDepthBits = 24;

		$format = $this->gdi32->ChoosePixelFormat($this->hdc, FFI::addr($pfd));
		$this->gdi32->SetPixelFormat($this->hdc, $format, FFI::addr($pfd));

		$this->hglrc = $this->opengl32->wglCreateContext($this->hdc);
		$this->opengl32->wglMakeCurrent($this->hdc, $this->hglrc);

		// Enable VSync
		try {
			$proc = $this->opengl32->wglGetProcAddress("wglSwapIntervalEXT");
			if ($proc) {
				$wglSwapIntervalEXT = FFI::cast("PFNWGLSWAPINTERVALEXTPROC", $proc);
				$res = $wglSwapIntervalEXT(1);
				$this->log("VSync enabled via wglSwapIntervalEXT (Result: $res)");
			} else {
				$this->log("wglSwapIntervalEXT not found, using DwmFlush fallback");
			}
		} catch (\Throwable $e) {
			$this->log("Failed to enable VSync extension: " . $e->getMessage(), "WARN");
		}

		$this->initFonts();

		$this->user32->ShowWindow($this->hwnd, 1);
		$this->user32->UpdateWindow($this->hwnd);
	}

	private function initFonts()
	{
		$fontDir = self::DATA_DIR . "/fonts";
		$fonts = [];
		if (is_dir($fontDir)) {
			foreach (glob("$fontDir/*.ttf") as $ttf) {
				$this->gdi32->AddFontResourceExA($ttf, 0x10, null);
				// Derive font face name from filename: "OpenSans-Regular.ttf" -> "Open Sans"
				$base = pathinfo($ttf, PATHINFO_FILENAME);
				$base = preg_replace(
					'/-(Regular|Bold|Italic|Light|Medium|SemiBold|ExtraBold|Black|Thin)$/i',
					"",
					$base,
				);
				$face = preg_replace("/([a-z])([A-Z])/", '$1 $2', $base);
				if (!in_array($face, $fonts)) {
					$fonts[] = $face;
				}
			}
		}
		if (!empty($fonts)) {
			$this->availableFonts = $fonts;
			$this->log(
				"Font discovery: " .
					count($fonts) .
					" fonts found in $fontDir.",
			);
		} else {
			$this->log(
				"No custom fonts found in $fontDir, using system fallback.",
				"WARN",
			);
		}
		// Build texture atlases with GDI anti-aliasing
		$this->buildFontAtlas(1000, 20, 400); // Normal 20px
		$this->buildFontAtlas(2000, 28, 700); // Bold 28px
		$this->buildFontAtlas(3000, 15, 400); // Small 15px
	}

	private function buildFontAtlas($listBase, $fontSize, $fontWeight)
	{
		$gdi = $this->gdi32;
		$gl = $this->opengl32;

		// Create font with ANTIALIASED_QUALITY
		$fontFace = $this->settings["font_launcher"] ?? "Open Sans";
		$this->log(
			"Building font atlas: $fontFace ({$fontSize}px, weight $fontWeight)",
		);
		$font = $gdi->CreateFontA(
			$fontSize,
			0,
			0,
			0,
			$fontWeight,
			0,
			0,
			0,
			1,
			4,
			0,
			4,
			2,
			$fontFace,
		); // DEFAULT_CHARSET, OUT_TT_PRECIS, ANTIALIASED_QUALITY

		// Create memory DC and DIB
		$memDC = $gdi->CreateCompatibleDC($this->hdc);
		$gdi->SelectObject($memDC, $font);

		// Measure all printable ASCII chars to determine atlas width
		$glyphs = [];
		$totalW = 0;
		$charSize = $gdi->new("SIZE");
		for ($ch = 32; $ch < 127; $ch++) {
			$c = chr($ch);
			$gdi->GetTextExtentPoint32A($memDC, $c, 1, FFI::addr($charSize));
			$glyphs[$ch] = ["x" => $totalW, "w" => $charSize->cx];
			$totalW += $charSize->cx + 2; // 2px spacing
		}

		// Atlas dimensions (power-of-two width)
		$atlasW = 1;
		while ($atlasW < $totalW) {
			$atlasW *= 2;
		}
		$atlasH = 1;
		$rowH = $fontSize + 4;
		while ($atlasH < $rowH) {
			$atlasH *= 2;
		}

		// Create DIB section (top-down 32-bit BGRA)
		$bmi = $gdi->new("BITMAPINFO");
		$bmi->bmiHeader->biSize = FFI::sizeof($bmi->bmiHeader);
		$bmi->bmiHeader->biWidth = $atlasW;
		$bmi->bmiHeader->biHeight = -$atlasH; // negative = top-down
		$bmi->bmiHeader->biPlanes = 1;
		$bmi->bmiHeader->biBitCount = 32;
		$bmi->bmiHeader->biCompression = 0; // BI_RGB

		$ppvBits = FFI::new("void*[1]");
		$hBitmap = $gdi->CreateDIBSection(
			$memDC,
			FFI::addr($bmi),
			0,
			FFI::addr($ppvBits[0]),
			null,
			0,
		);
		$gdi->SelectObject($memDC, $hBitmap);

		// Setup text rendering: white text on black background
		$gdi->SetBkMode($memDC, 1); // OPAQUE
		$gdi->SetBkColor($memDC, 0x00000000); // Black
		$gdi->SetTextColor($memDC, 0x00ffffff); // White

		// Re-select font after selecting bitmap
		$gdi->SelectObject($memDC, $font);

		// Render each character
		for ($ch = 32; $ch < 127; $ch++) {
			$c = chr($ch);
			$gdi->TextOutA($memDC, $glyphs[$ch]["x"], 1, $c, 1);
		}

		// Read DIB pixels and build RGBA texture data
		$pixelCount = $atlasW * $atlasH;
		$dibPixels = FFI::cast(
			"unsigned char[" . $pixelCount * 4 . "]",
			$ppvBits[0],
		);
		$texData = FFI::new("unsigned char[" . $pixelCount * 4 . "]");

		for ($i = 0; $i < $pixelCount; $i++) {
			$r = $dibPixels[$i * 4 + 2]; // DIB is BGRA, R is at offset 2
			$texData[$i * 4 + 0] = 255; // R = white
			$texData[$i * 4 + 1] = 255; // G = white
			$texData[$i * 4 + 2] = 255; // B = white
			$texData[$i * 4 + 3] = $r; // A = luminance (anti-aliased alpha)
		}

		// Create OpenGL texture
		$texIdBuf = $gl->new("UINT[1]");
		$gl->glGenTextures(1, FFI::addr($texIdBuf[0]));
		$texId = $texIdBuf[0];
		$gl->glBindTexture(0x0de1, $texId); // GL_TEXTURE_2D
		$gl->glTexParameteri(0x0de1, 0x2801, 0x2601); // MIN_FILTER = LINEAR
		$gl->glTexParameteri(0x0de1, 0x2800, 0x2601); // MAG_FILTER = LINEAR
		$gl->glTexImage2D(
			0x0de1,
			0,
			0x1908,
			$atlasW,
			$atlasH,
			0,
			0x1908,
			0x1401,
			$texData,
		); // GL_RGBA, GL_UNSIGNED_BYTE
		$gl->glBindTexture(0x0de1, 0);

		// Store atlas info
		$this->fontAtlas[$listBase] = [
			"texId" => $texId,
			"glyphs" => $glyphs,
			"height" => $fontSize,
			"atlasW" => $atlasW,
			"atlasH" => $atlasH,
		];

		// Cleanup GDI objects
		$gdi->DeleteDC($memDC);
		$gdi->DeleteObject($hBitmap);
		$gdi->DeleteObject($font);
	}

	private function renderText($text, $x, $y, $color, $listBase = 1000)
	{
		$gl = $this->opengl32;
		if (!isset($this->fontAtlas[$listBase])) {
			return;
		}
		$atlas = $this->fontAtlas[$listBase];

		$gl->glEnable(0x0de1); // GL_TEXTURE_2D
		$gl->glBindTexture(0x0de1, $atlas["texId"]);
		$a = (count($color) > 3 ? $color[3] : 1.0) * $this->globalAlpha;
		$gl->glColor4f($color[0], $color[1], $color[2], $a);
		$gl->glBegin(0x0007); // GL_QUADS

		$curX = $x;
		$h = $atlas["height"];
		$aw = $atlas["atlasW"];
		$ah = $atlas["atlasH"];
		// y is baseline; render text from (y - h*0.8) to (y + h*0.2)
		$top = $y - $h * 0.75;
		$bot = $top + $h;

		for ($i = 0; $i < strlen($text); $i++) {
			$ch = ord($text[$i]);
			if ($ch < 32 || $ch >= 127) {
				$curX += 6;
				continue;
			}
			$g = $atlas["glyphs"][$ch];
			$u1 = $g["x"] / $aw;
			$u2 = ($g["x"] + $g["w"]) / $aw;
			$v1 = 0.0; // top of atlas (top-down DIB → top row at t=0)
			$v2 = ($h + 2) / $ah;

			$gl->glTexCoord2f($u1, $v1);
			$gl->glVertex2f($curX, $top);
			$gl->glTexCoord2f($u2, $v1);
			$gl->glVertex2f($curX + $g["w"], $top);
			$gl->glTexCoord2f($u2, $v2);
			$gl->glVertex2f($curX + $g["w"], $bot);
			$gl->glTexCoord2f($u1, $v2);
			$gl->glVertex2f($curX, $bot);

			$curX += $g["w"];
		}

		$gl->glEnd();
		$gl->glDisable(0x0de1);
		$gl->glBindTexture(0x0de1, 0);
	}

	private function getTextWidth($text, $listBase = 1000)
	{
		if (!isset($this->fontAtlas[$listBase])) {
			return 0;
		}
		$atlas = $this->fontAtlas[$listBase];
		$w = 0;
		for ($i = 0; $i < strlen($text); $i++) {
			$ch = ord($text[$i]);
			if (isset($atlas["glyphs"][$ch])) {
				$w += $atlas["glyphs"][$ch]["w"];
			} else {
				$w += 6;
			}
		}
		return $w;
	}

	// ─── Scroll helpers ───
	private function getMaxScroll()
	{
		$tabIdx =
			$this->currentPage === self::PAGE_FOXYCLIENT ? 0 : $this->activeTab;
		$mods = $this->tabs[$tabIdx]["mods"] ?? [];
		if ($this->currentPage === self::PAGE_MODS) {
			if ($this->modpackSubTab === 2) {
				$modsCount = count($this->installedModpacks);
				$contentH = $modsCount * (72 + 8) + 20;
			} else {
				$modsCount = count($this->modrinthSearchResults);
				$rows = ceil($modsCount / 2);
				$contentH = $rows * (110 + 12) + 20;
			}
		} else {
			$modsCount = count($mods);
			$contentH = $modsCount * (self::CARD_H + self::CARD_GAP);
		}
		$viewH =
			$this->height -
			self::TITLEBAR_H -
			self::HEADER_H -
			self::TAB_H -
			self::FOOTER_H;
		return max(0, $contentH - $viewH);
	}

	private function switchPage($page)
	{
		if ($this->currentPage === $page) {
			return;
		}
		$this->currentPage = $page;
		$this->pageAnim = 0.0;
		$this->scrollTarget = 0;
		$this->scrollOffset = 0;
		$this->vScrollTarget = 0;
		$this->vScrollOffset = 0;

		$itemH = 50;
		$y = 100;
		foreach ($this->sidebarItems as $item) {
			if ($item["id"] === $page) {
				$this->sidebarTargetY = $y;
				break;
			}
			$y += $itemH + 5;
		}

		$this->updateDiscordPresence();
		if ($page === self::PAGE_MODS) {
			$this->searchModrinth($this->modSearchQuery);
		}
	}

	private function getMaxVersionScroll()
	{
		$filtered = $this->getFilteredVersions();
		$contentH = count($filtered) * 50; // 50px per version item (44+6)
		$viewH = $this->height - self::TITLEBAR_H - 110 - 150; // List top (110) - action area (150)
		return max(0, $contentH - $viewH);
	}

	// ─── Input handlers ───
	private function handleMouseClick($x, $y)
	{
		// Title bar click
		if ($y < self::TITLEBAR_H) {
			if ($this->titleCloseHover) {
				$this->user32->DestroyWindow($this->hwnd);
				return;
			}
			if ($this->titleMinHover) {
				$this->user32->ShowWindow($this->hwnd, 6); // SW_MINIMIZE
				return;
			}
			if ($this->titleDragHover) {
				$this->performNativeDrag();
				return;
			}
			return;
		}

		if ($this->bgModalOpen) {
			$this->handleBgModalClick($x, $y);
			return;
		}

		if ($this->javaModalOpen) {
			$this->handleJavaModalClick($x, $y);
			return;
		}

		// Sidebar clicks
		if ($x < self::SIDEBAR_W && $y >= self::TITLEBAR_H) {
			// Profile area click
			if ($y >= $this->height - 74 && $y <= $this->height - 24) {
				$this->currentPage = self::PAGE_ACCOUNTS;
				$this->updateDiscordPresence();
				return;
			}

			$itemH = 50;
			$startY = 100 + self::TITLEBAR_H;
			foreach ($this->sidebarItems as $i => $item) {
				if ($y >= $startY && $y < $startY + $itemH) {
					$this->switchPage($item["id"]);
					return;
				}
				$startY += $itemH + 5;
			}
			return;
		}

		// Header Click (Version subtitle)
		if (
			$this->currentPage !== self::PAGE_MODS &&
			$y >= 40 + self::TITLEBAR_H &&
			$y <= 60 + self::TITLEBAR_H &&
			$x >= self::SIDEBAR_W + self::PAD &&
			$x <= self::SIDEBAR_W + 300
		) {
			$this->switchPage(self::PAGE_VERSIONS);
			return;
		}

		// Content clicks
		$cx = $x - self::SIDEBAR_W;
		$cy = $y - self::TITLEBAR_H;

		if ($this->handleFooterClick($cx, $cy)) {
			return;
		}

		switch ($this->currentPage) {
			case self::PAGE_HOME:
				$this->handleHomePageClick($cx, $cy);
				break;
			case self::PAGE_MODS:
				$this->handleModsPageClick($cx, $cy);
				break;
			case self::PAGE_LOGIN:
				$this->handleLoginPageClick($cx, $cy);
				break;
			case self::PAGE_VERSIONS:
				$this->handleVersionsPageClick($cx, $cy);
				break;
			case self::PAGE_ACCOUNTS:
				$this->handleAccountsPageClick($cx, $cy);
				break;
			case self::PAGE_FOXYCLIENT:
				$this->handleFoxyClientSettingsClick($cx, $cy);
				break;
			case self::PAGE_PROPERTIES:
				$this->handlePropertiesPageClick($cx, $cy);
				break;
		}
	}

	private function handleMouseMove($x, $y)
	{
		if (!$this->isDraggingScroll) {
			return;
		}

		$dy = $y - $this->dragStartY;
		$maxScroll = 0;
		$viewH = 0;
		$scrollRef = null;
		$offsetRef = null;

		switch ($this->dragType) {
			case "mods":
				$maxScroll = $this->getMaxScroll();
				$viewH =
					$this->height -
					self::TITLEBAR_H -
					self::HEADER_H -
					self::TAB_H -
					self::FOOTER_H;
				if ($maxScroll > 0) {
					$thumbH = max(
						20,
						$viewH * ($viewH / ($maxScroll + $viewH)),
					);
					$delta = ($dy / ($viewH - $thumbH)) * $maxScroll;
					$this->scrollTarget = max(
						0,
						min($maxScroll, $this->dragStartOffset + $delta),
					);
				}
				break;
			case "versions":
				$maxScroll = $this->getMaxVersionScroll();
				$viewH = $this->height - self::TITLEBAR_H - 110 - 150;
				if ($maxScroll > 0) {
					$filtered = $this->getFilteredVersions();
					$contentH = count($filtered) * 40; // Matches renderScrollbarVersions logic
					$thumbH = max(20, ($viewH / $contentH) * $viewH);
					$delta = ($dy / ($viewH - $thumbH)) * $maxScroll;
					$this->vScrollTarget = max(
						0,
						min($maxScroll, $this->dragStartOffset + $delta),
					);
				}
				break;
			case "prop":
				$maxScroll = 200; // Hardcoded max in existing mousewheel logic
				$viewH = $this->height - self::TITLEBAR_H - 120; // Approx
				$thumbH = max(30, $viewH * ($viewH / ($maxScroll + $viewH)));
				$delta = ($dy / ($viewH - $thumbH)) * $maxScroll;
				$this->propScrollTarget = max(
					0,
					min($maxScroll, $this->dragStartOffset + $delta),
				);
				break;
			case "home_dropdown":
				$contentH = count($this->getHomeVersions()) * 40;
				$viewH = 200;
				$maxScroll = max(0, $contentH - $viewH);
				if ($maxScroll > 0) {
					$thumbH = max(20, ($viewH / $contentH) * $viewH);
					$delta = ($dy / ($viewH - $thumbH)) * $maxScroll;
					$this->homeVerScrollTarget = max(
						0,
						min($maxScroll, $this->dragStartOffset + $delta),
					);
				}
				break;
		}
	}

	private function handleAccountsPageClick($cx, $cy)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$usableH = $this->height - self::TITLEBAR_H;

		// Add Button Click
		$addBtnW = 150;
		$addBtnH = 36;
		$addBtnX = $cw - self::PAD - $addBtnW;
		$addBtnY = 32;
		if ($cy >= $addBtnY && $cy <= $addBtnY + $addBtnH && $cx >= $addBtnX && $cx <= $addBtnX + $addBtnW) {
			$this->switchPage(self::PAGE_LOGIN);
			$this->loginInput = "";
			$this->loginStep = 0; // Force account type selection
			return;
		}

		// Account list scrolling viewport
		$listTop = 110;
		$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
		$listH = $usableH - $footerH - $listTop;
		if ($cy >= $listTop && $cy <= $listTop + $listH && $cx >= self::PAD && $cx <= $cw - self::PAD) {
			$itemH = 64;
			$gap = 6;
			$localY = $cy - $listTop + $this->accScrollOffset;
			$idx = (int) floor($localY / ($itemH + $gap));
			$uuids = array_keys($this->accounts);
			
			if (isset($uuids[$idx])) {
				$uuid = $uuids[$idx];
				$itemLocalY = $localY - $idx * ($itemH + $gap);
				if ($itemLocalY <= $itemH) { 
					// Delete button check
					$delW = 32;
					$delX = $cw - self::PAD - $delW - 16;
					if ($cx >= $delX) {
						unset($this->accounts[$uuid]);
						if ($this->activeAccount === $uuid) {
							if (count($this->accounts) > 0) {
								$this->selectAccount(array_key_first($this->accounts));
							} else {
								$this->logout();
							}
						}
						$this->saveAccounts();
					} else {
						$this->selectAccount($uuid);
						$this->switchPage(self::PAGE_HOME);
					}
				}
			}
		}
	}

	private function handleVersionsPageClick($cx, $cy)
	{
		// Scrollbar interaction
		$maxScroll = $this->getMaxVersionScroll();
		if ($maxScroll > 0) {
			$cw = $this->width - self::SIDEBAR_W;
			$barX = $cw - 15; // Hitbox
			if ($cx >= $barX && $cx <= $cw) {
				$usableH = $this->height - self::TITLEBAR_H;
				$listTop = 140; // 100 + HEADER_H
				$listH = $usableH - $listTop - 150;
				$filtered = $this->getFilteredVersions();
				$contentH = count($filtered) * 62;
				$thumbH = max(20, ($listH / $contentH) * $listH);
				$thumbY =
					($this->vScrollOffset / $maxScroll) * ($listH - $thumbH);
				$absThumbY = $listTop + $thumbY; // Corrected variable name
				if ($cy >= $absThumbY && $cy <= $absThumbY + $thumbH) {
					$this->isDraggingScroll = true;
					$this->dragType = "versions";
					$this->dragStartY = $this->mouseY;
					$this->dragStartOffset = $this->vScrollOffset;
					return;
				}
			}
		}

		$cw = $this->width - self::SIDEBAR_W;

		// Category tabs (Y=100, H=40)
		if ($cy >= 100 && $cy < 140) {
			$tx = self::PAD;
			$cats = ["RELEASES", "SNAPSHOTS", "MODIFIED"];
			foreach ($cats as $i => $cat) {
				$tw = $this->getTextWidth($cat, 1000) + 30;
				if ($cx >= $tx && $cx < $tx + $tw) {
					$this->vCategory = $i;
					$this->vScrollTarget = 0;
					$this->vScrollOffset = 0;
					$this->filteredVersionsCache = null; // force refresh
					return;
				}
				$tx += $tw;
			}
		}

		// Retry button click
		if (!$this->versionsLoaded && $this->isFetchingVersions) {
			if ($cy >= 230 && $cy <= 266 && $cx >= self::PAD && $cx <= self::PAD + 100) {
				$this->isFetchingVersions = false;
				$this->loadVersions();
				return;
			}
		}

		$usableH = $this->height - self::TITLEBAR_H;
		$listTop = 140;
		$actionY = $usableH - 150;

		// Version list
		if ($cy >= $listTop && $cy < $actionY) {
			$filtered = $this->getFilteredVersions();
			$localY = $cy - $listTop + $this->vScrollOffset;
			$idx = (int) floor($localY / 62);
			if ($idx >= 0 && $idx < count($filtered)) {
				$this->selectedVersion = $filtered[$idx]["id"];
				$this->config["minecraft_version"] = $this->selectedVersion;
				$this->saveConfig();

				$this->assetProgress = 0;
				$this->isDownloadingAssets = false;
			}
			return;
		}

		// Download / Uninstall Action buttons
		if ($this->selectedVersion) {
			$btnW = 200;
			$btnH = 36;
			
			if ($cx >= self::PAD && $cx <= self::PAD + $btnW && $cy >= $actionY + 45 && $cy <= $actionY + 45 + $btnH) {
				$this->triggerVersionDownload($this->selectedVersion, true);
				return;
			}
			
			$jarPath = $this->settings["game_dir"] . DIRECTORY_SEPARATOR . "versions" . DIRECTORY_SEPARATOR . $this->selectedVersion . DIRECTORY_SEPARATOR . $this->selectedVersion . ".jar";
			if (file_exists($jarPath)) {
				if ($cx >= self::PAD + $btnW + 16 && $cx <= self::PAD + $btnW + 16 + 150 && $cy >= $actionY + 45 && $cy <= $actionY + 45 + $btnH) {
					$vDir = dirname($jarPath);
					$this->deleteDirectory($vDir);
					$this->assetMessage = "VERSION UNINSTALLED";
					$this->filteredVersionsCache = null; // Refresh list
					return;
				}
			}
		}
	}

	private function triggerVersionDownload($vId, $autoLaunch = false)
	{
		$this->shouldAutoLaunchAfterDownload = $autoLaunch;
		if (!$this->isDownloadingAssets && !$this->assetProcess) {
			$this->isDownloadingAssets = true;
			$this->assetProgress = 0.0;
			$this->assetMessage = "STARTING...";

			// Launch parallel background downloader
			$this->assetChannel = new \parallel\Channel();
			putenv("FOXY_BACKGROUND=1");
			$this->assetProcess = new \parallel\Runtime(__FILE__);
			putenv("FOXY_BACKGROUND=0");
			$cacert = __DIR__ . DIRECTORY_SEPARATOR . self::CACERT;
			$this->assetFuture = $this->assetProcess->run(
				function (
					\parallel\Channel $ch,
					string $version,
					string $gamesDir,
					string $cacert,
				) {
					FoxyVersionJob::run($ch, $version, $gamesDir, $cacert);
				},
				[
					$this->assetChannel,
					$vId,
					$this->settings["game_dir"],
					$cacert,
				],
			);
			$this->pollEvents->addChannel($this->assetChannel);
		}
	}

	private function handleLoginPageClick($cx, $cy)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$boxW = 300;
		$boxX = ($cw - $boxW) / 2;

		// Back button
		if (
			$cx >= self::PAD &&
			$cx <= self::PAD + 80 &&
			$cy >= 20 &&
			$cy <= 50
		) {
			if ($this->loginStep === 1) {
				$this->loginStep = 0;
				$this->msDeviceCode = "";
				return;
			}
			// Step 0 -> Back to accounts
			$this->currentPage = self::PAGE_ACCOUNTS;
			return;
		}

		// Step 0: Select login type
		if ($this->loginStep === 0) {
			$y = 120;
			$types = [
				self::ACC_OFFLINE,
				self::ACC_MICROSOFT,
				self::ACC_FOXY,
				self::ACC_ELYBY,
			];
			foreach ($types as $tid) {
				if (
					$cx >= $boxX &&
					$cx <= $boxX + $boxW &&
					$cy >= $y &&
					$cy <= $y + 40
				) {
					$this->loginType = $tid;
					$this->loginInput = "";
					$this->loginInputPassword = "";
					$this->msError = "";
					if ($this->loginType === self::ACC_MICROSOFT) {
						$this->startMicrosoftOAuth();
						// Only connect to Microsoft OAuth if needed
						$this->loginStep = 1;
					} else {
						$this->loginStep = 1;
					}
					return;
				}
				$y += 50;
			}
			return;
		}

		// Step 1: Input / Auth process
		if ($this->loginType === self::ACC_OFFLINE) {
			// Input box click
			if (
				$cx >= $boxX &&
				$cx <= $boxX + $boxW &&
				$cy >= 200 &&
				$cy <= 240
			) {
				$this->inputFocus = true;
			} else {
				$this->inputFocus = false;
			}
			// Login button
			if (
				$cx >= $boxX &&
				$cx <= $boxX + $boxW &&
				$cy >= 260 &&
				$cy <= 300
			) {
				if (!empty($this->loginInput)) {
					$name = $this->loginInput;
					$uuid = $this->generateUUID();
					$this->log("Logged in with offline account: $name");
					$this->accounts[$uuid] = [
						"Username" => $name,
						"Type" => self::ACC_OFFLINE,
					];
					$this->selectAccount($uuid);
					$this->currentPage = self::PAGE_HOME;
					$this->loginStep = 0;
				}
			}
		} elseif ($this->loginType === self::ACC_ELYBY) {
			// Method Selector
			$btnW = ($boxW - 10) / 2;
			if ($cy >= 175 && $cy <= 205) {
				if ($cx >= $boxX && $cx <= $boxX + $btnW) {
					$this->elyLoginMethod = "oauth2";
					return;
				}
				if ($cx >= $boxX + $btnW + 10 && $cx <= $boxX + $boxW) {
					$this->elyLoginMethod = "classic";
					return;
				}
			}

			if ($this->elyLoginMethod === "oauth2") {
				// Link Button
				if ($cx >= $boxX && $cx <= $boxX + $boxW && $cy >= 240 && $cy <= 280) {
					$this->oauthState = md5(microtime(true) . "foxy");
					$redirectUri = "http://localhost:" . $this->oauthPort . "/callback";
					
					$params = [
						"client_id" => $this->elyClientId,
						"client_secret" => $this->elyClientSecret,
						"response_type" => "code",
						"scope" => "account_info minecraft_server_session",
						"redirect_uri" => $redirectUri,
						"state" => $this->oauthState,
						"prompt" => "select_account"
					];
					// Use PHP_QUERY_RFC3986 to get %20 instead of + for spaces
					$url = "https://account.ely.by/oauth2/v1?" . http_build_query($params, "", "&", PHP_QUERY_RFC3986);
					
					pclose(popen("start \"\" \"$url\"", "r"));
					$this->loginStep = 1;
					$this->inputFocus = "oauth_code";
					$this->startOAuthListener(); // Start local server
				}
			} else {
				// Classic Login
				if ($cx >= $boxX && $cx <= $boxX + $boxW && $cy >= 225 && $cy <= 265) {
					$this->inputFocus = "username";
				} elseif ($cx >= $boxX && $cx <= $boxX + $boxW && $cy >= 295 && $cy <= 335) {
					$this->inputFocus = "password";
				} else {
					$this->inputFocus = false;
				}

				if ($cx >= $boxX && $cx <= $boxX + $boxW && $cy >= 360 && $cy <= 400) {
					if (!empty($this->loginInput) && !empty($this->loginInputPassword)) {
						$this->authenticateElybyClassic($this->loginInput, $this->loginInputPassword);
					}
				}
			}
		} elseif ($this->loginType === self::ACC_FOXY) {
			// Method Selector
			$btnW = ($boxW - 10) / 2;
			if ($cy >= 175 && $cy <= 205) {
				if ($cx >= $boxX && $cx <= $boxX + $btnW) {
					$this->foxyLoginMethod = "oauth2";
					return;
				}
				if ($cx >= $boxX + $btnW + 10 && $cx <= $boxX + $boxW) {
					$this->foxyLoginMethod = "classic";
					return;
				}
			}

			if ($this->foxyLoginMethod === "oauth2") {
				// Link Button
				if ($cx >= $boxX && $cx <= $boxX + $boxW && $cy >= 240 && $cy <= 280) { // Matches render coordinate
					$this->oauthState = md5(microtime(true) . "foxy_foxy");
					$params = [
						"client_id" => $this->foxyClientId,
						"response_type" => "code",
						"redirect_uri" => $this->foxyRedirectUri,
						"state" => $this->oauthState,
					];
					$url = "https://foxyclient.qzz.io/oauth/authorize/?" . http_build_query($params);
					pclose(popen("start \"\" \"$url\"", "r"));
					$this->loginStep = 1;
					$this->startOAuthListener();
				}
			} else {
				// Classic Login for FoxyClient
				if ($cx >= $boxX && $cx <= $boxX + $boxW && $cy >= 225 && $cy <= 265) {
					$this->inputFocus = "username";
				} elseif ($cx >= $boxX && $cx <= $boxX + $boxW && $cy >= 295 && $cy <= 335) {
					$this->inputFocus = "password";
				} else {
					$this->inputFocus = false;
				}

				if ($cx >= $boxX && $cx <= $boxX + $boxW && $cy >= 360 && $cy <= 400) {
					if (!empty($this->loginInput) && !empty($this->loginInputPassword)) {
						$this->authenticateFoxyClassic($this->loginInput, $this->loginInputPassword);
					}
				}
			}
		} elseif ($this->loginType === self::ACC_MICROSOFT) {
			// Cancel button
			if (
				$cx >= $boxX &&
				$cx <= $boxX + $boxW &&
				$cy >= 330 &&
				$cy <= 370
			) {
				$this->loginStep = 0;
				$this->msDeviceCode = "";
			}
		}
	}

	private function handleModsPageClick($cx, $cy)
	{
		$cw = $this->width - self::SIDEBAR_W;

		// Sub-tabs (Mods, Modpacks, Installed)
		if ($cy >= self::HEADER_H && $cy <= self::HEADER_H + self::TAB_H) {
			$tabX = self::PAD;
			foreach (["Mods", "Modpacks", "Installed"] as $i => $name) {
				$tw = $this->getTextWidth($name, 1000) + 32;
				if ($cx >= $tabX && $cx <= $tabX + $tw) {
					if ($this->modpackSubTab !== $i) {
						$this->modpackSubTab = $i;
						$this->scrollOffset = 0;
						$this->scrollTarget = 0;
						$this->hoverModIndex = -1;
						$this->modrinthAnim = 0.0; // Trigger transition
						$this->modrinthPage = 0;
						$this->modPageTarget = 0;
						$this->modPageDebounceTimer = 0;
						// ALWAYS search on sub-tab switch for dedicated browser feel
						// searchModrinth will handle cache cleanup internally
						$this->searchModrinth();
						if ($i === 2) {
							$this->checkModpackIcons();
						}
					}
					return;
				}
				$tabX += $tw + 8;
			}
		}

		// Search Bar focus
		$searchW = 300;
		$searchX = $cw - self::PAD - $searchW;
		if (
			$cx >= $searchX &&
			$cx <= $searchX + $searchW &&
			$cy >= 20 &&
			$cy <= 60
		) {
			$this->modSearchFocus = true;
			return;
		}
		$this->modSearchFocus = false;

		// Filter Pill Clicks
		if ($this->modsFilterDropdown === "" && $this->modsFilterDropdownAnim <= 0.01) {
			foreach ($this->modsFilterPillRects as $key => $rect) {
				if ($cx >= $rect[0] && $cx <= $rect[0] + $rect[2] && 
					$cy >= $rect[1] && $cy <= $rect[1] + $rect[3]) {
					$this->modsFilterDropdown = $key;
					$this->modsFilterScrollTarget = 0;
					$this->modsFilterScrollOffset = 0;
					return;
				}
			}
		}

		// Dropdown interaction if open
		if ($this->modsFilterDropdown !== "") {
			$key = $this->modsFilterDropdown;
			$pillRect = $this->modsFilterPillRects[$key] ?? null;
			if ($pillRect) {
				$ddX = $pillRect[0];
				$ddY = $pillRect[1] + $pillRect[3] + 4;
				$ddW = $key === "env" ? 150 : 220;
				
				// Rebuild items to calculate height
				$items = [];
				if ($key === "category") {
					$items[] = ["", "All Categories"];
					foreach ($this->modsCategories as $cat) {
						$items[] = [$cat, $this->modsCategoryLabels[$cat] ?? $cat];
					}
				} elseif ($key === "loader") {
					$items[] = ["", "All Loaders"];
					foreach ($this->modsLoaderList as $ld) {
						$items[] = [$ld, $this->modsLoaderLabels[$ld] ?? ucfirst($ld)];
					}
				} elseif ($key === "env") {
					$items = [["", "All"], ["client", "Client"], ["server", "Server"]];
				} elseif ($key === "version") {
					$releaseVersions = [];
					foreach ($this->versions as $v) {
						if (($v["type"] ?? "") === "release") {
							$releaseVersions[] = $v["id"];
						}
					}
					if (empty($releaseVersions)) {
						$releaseVersions = [$this->config["minecraft_version"]];
					}
					usort($releaseVersions, function ($a, $b) {
						return version_compare($b, $a);
					});
					foreach ($releaseVersions as $ver) {
						$items[] = [$ver, $ver];
					}
				}

				if (!empty($items)) {
					$itemH = 30;
					$maxVisible = min(10, count($items));
					$fullH = $maxVisible * $itemH;

					if ($cx >= $ddX && $cx <= $ddX + $ddW && $cy >= $ddY && $cy <= $ddY + $fullH) {
						$localY = $cy - $ddY + $this->modsFilterScrollOffset;
						$idx = (int) floor($localY / $itemH);
						if ($idx >= 0 && $idx < count($items)) {
							$val = $items[$idx][0];
							if ($key === "category") {
								$this->modsFilterCategory = $val;
							} elseif ($key === "loader") {
								$this->modsFilterLoader = $val;
							} elseif ($key === "env") {
								$this->modsFilterEnv = $val;
							} elseif ($key === "version") {
								$this->setModsVersion($val);
							}
							
							$this->modsFilterDropdown = ""; // Close
							if ($this->modSearchQuery !== null) {
								$this->searchModrinth();
							}
							return;
						}
						return; // Clicked inside dropdown but not on item, still eat the click
					}
				}
			}
			// Clicked outside dropdown
			$this->modsFilterDropdown = "";
			return;
		}

		if ($this->modpackSubTab === 2) {
			// Installed tab - uninstall button clicks
			$y = self::HEADER_H + self::TAB_H + 10 - $this->scrollOffset;
			if ($this->isInstallingModpack || $this->modpackInstallProgress !== "") {
				$y += 46;
			}
			$idx = 0;
			foreach ($this->installedModpacks as $slug => $pack) {
				$cardH = 72;
				$btnW = 100;
				$btnH = 32;
				$btnX = $cw - self::PAD * 2 - $btnW - 10;
				$btnY2 = $y + ($cardH - $btnH) / 2;

				// Play button detection
				$pBtnW = 80;
				$pBtnX = $cw - self::PAD * 2 - $btnW - 20 - $pBtnW;
				if ($cx >= $pBtnX && $cx <= $pBtnX + $pBtnW && $cy >= $btnY2 && $cy <= $btnY2 + $btnH) {
					$this->launchModpack($slug);
					return;
				}

				// Uninstall button detection
				if ($cx >= $btnX && $cx <= $btnX + $btnW && $cy >= $btnY2 && $cy <= $btnY2 + $btnH) {
					$this->uninstallModpack($slug);
					return;
				}
				$y += $cardH + 8;
				$idx++;
			}
		}

		if ($this->modsVerDropdownOpen) {
			$this->modsVerDropdownOpen = false;
			return;
		}

		$y = self::HEADER_H + self::TAB_H;
		if ($this->currentPage === self::PAGE_MODS && ($this->modpackSubTab === 1 || $this->modpackSubTab === 2) && ($this->isInstallingModpack || $this->modpackInstallProgress !== "")) {
			$y += 46;
		}
		$usableH = $this->height - self::TITLEBAR_H;
		$showFooter = $this->getFooterVisibility();
		$effectiveFooterH = $showFooter ? self::FOOTER_H : 0;
		$h = $usableH - $effectiveFooterH - $y;

		// Discovery Mode - Priority 1: Pagination (Fixed Window-Relative to prevent clipping)
		if (
			$this->currentPage === self::PAGE_MODS &&
			$this->modrinthTotalHits > 20
		) {
			$pgY = $usableH - $effectiveFooterH - 45; // Fixed 5px above footer or bottom
			$pgW = 200;
			$pgX = ($cw - $pgW) / 2;

			// Prev Button
			if (
				$cx >= $pgX &&
				$cx <= $pgX + 60 &&
				$cy >= $pgY &&
				$cy <= $pgY + 30
			) {
				if ($this->modrinthPage > 0) {
					$this->modrinthPage--;
					$this->modPageTarget = $this->modrinthPage;
					$this->modPageDebounceTimer = 0;
					$this->searchModrinth($this->modSearchQuery, $this->modrinthPage);
					$this->needsRedraw = true;
				}
				return;
			}

			// Next Button
			$nextX = $pgX + $pgW - 60;
			if (
				$cx >= $nextX &&
				$cx <= $nextX + 60 &&
				$cy >= $pgY &&
				$cy <= $pgY + 30
			) {
				$totalPages = ceil($this->modrinthTotalHits / 20);
				if ($this->modrinthPage + 1 < $totalPages) {
					$this->modrinthPage++;
					$this->modPageTarget = $this->modrinthPage;
					$this->modPageDebounceTimer = 0;
					$this->searchModrinth($this->modSearchQuery, $this->modrinthPage);
					$this->needsRedraw = true;
				}
				return;
			}
		}

		if (
			$cx >= self::PAD &&
			$cx <= $cw - self::PAD &&
			$cy >= $y &&
			$cy <= $y + $h
		) {
			if ($this->currentPage === self::PAGE_MODS) {
				// Grid logic for Modrinth
				$alpha = $this->modrinthAnim;
				$slideY = (1.0 - $alpha) * 20;
				$gridX = self::PAD;
				$gridY = $y + 10 - $this->scrollOffset + $slideY;
				$cardW = ($cw - self::PAD * 3) / 2;
				$cardH = 110;
				$gap = 12;

				foreach ($this->modrinthSearchResults as $i => $hit) {
					$col = $i % 2;
					$row = floor($i / 2);
					$itemX = $gridX + $col * ($cardW + $gap);
					$itemY = $gridY + $row * ($cardH + $gap);

					if ($itemY + $cardH > $y && $itemY < $y + $h) {
						$btnW = 90;
						$btnH = 32;
						$btnX = $itemX + $cardW - $btnW - 16;
						$btnY2 = $itemY + 16;

						// Check INSTALL button (rightmost)
						if (
							$cx >= $btnX &&
							$cx <= $btnX + $btnW &&
							$cy >= $btnY2 &&
							$cy <= $btnY2 + $btnH
						) {
							$slug = $hit["slug"] ?? $hit["project_id"];
							if (isset($this->installedModpacks[$slug]) || isset($this->installedModpacks[$hit["project_id"]])) {
								$this->log("Modpack already installed: " . ($hit["title"] ?? $slug));
								return;
							}

							$title =
								$hit["title"] ?? ($hit["slug"] ?? "Unknown");
							$this->log("Installing from Modrinth: " . $title);
							$this->installModrinthProject(
								$hit["project_id"],
								$hit["project_type"] ?? "mod",
								$title,
							);
							return;
						}

						// Check "External" icon (browser, left of install)
						$btnSize = 32;
						$btnGap = 8;
						$brX = $btnX - $btnSize - $btnGap;
						if (
							$cx >= $brX &&
							$cx <= $brX + $btnSize &&
							$cy >= $btnY2 &&
							$cy <= $btnY2 + $btnSize
						) {
							$slug = $hit["slug"] ?? $hit["id"];
							shell_exec(
								"explorer \"https://modrinth.com/project/$slug\"",
							);
							return;
						}
					}
				}
			} else {
				// Local Mods Mode
				$mods = $this->tabs[$this->activeTab]["mods"] ?? [];
				$itemY = $y + 10 - $this->scrollOffset;
				foreach ($mods as $i => $mod) {
					if ($itemY + self::CARD_H > $y && $itemY < $y + $h) {
						if (
							$cx >= self::PAD &&
							$cx <= $cw - self::PAD &&
							$cy >= $itemY &&
							$cy <= $itemY + self::CARD_H
						) {
							$this->tabs[$this->activeTab]["mods"][$i][
								"checked"
							] = !$mod["checked"];
							$this->saveConfig();
							return;
						}
					}
					$itemY += self::CARD_H + self::CARD_GAP;
				}
			}
		}
	}

	private function toggleVersion()
	{
		// Toggle version dropdown open/closed
		$this->modsVerDropdownOpen = !$this->modsVerDropdownOpen;
	}

	private function setModsVersion($version, $loader = null)
	{
		$this->config["minecraft_version"] = $version;
		if ($loader) {
			$this->config["loader"] = $loader;
		}
		$this->selectedVersion = $version;
		$this->saveConfig();
		$this->modsVerDropdownOpen = false;

		// Reset statuses and check compatibility
		foreach ($this->tabs as &$tab) {
			foreach ($tab["mods"] as &$mod) {
				$mod["status"] = "idle";
			}
		}
		$this->modCompatCache = [];
		$this->checkModCompatibility();
	}

	private function applyTheme()
	{
		$theme = $this->settings["theme"] ?? "dark";
		if ($theme === "light") {
			$this->colors = $this->lightColors;
		} else {
			$this->colors = $this->darkColors;
		}
	}

	private function copyToClipboard($text)
	{
		if (empty($text)) {
			return;
		}
		$u32 = $this->user32;
		$k32 = $this->kernel32;
		if ($u32->OpenClipboard($this->hwnd)) {
			$u32->EmptyClipboard();
			$len = strlen($text) + 1;
			$hMem = $k32->GlobalAlloc(0x0042, $len); // GHND = GMEM_MOVEABLE | GMEM_ZEROINIT
			if ($hMem) {
				$ptr = $k32->GlobalLock($hMem);
				if ($ptr) {
					FFI::memcpy($ptr, $text, strlen($text));
					$k32->GlobalUnlock($hMem);
					$u32->SetClipboardData(1, $hMem); // CF_TEXT = 1
				}
			}
			$u32->CloseClipboard();
		}
	}

	private function getClipboardText()
	{
		$u32 = $this->user32;
		$k32 = $this->kernel32;
		$text = "";
		if ($u32->OpenClipboard($this->hwnd)) {
			$hData = $u32->GetClipboardData(1); // CF_TEXT
			if ($hData) {
				$ptr = $k32->GlobalLock($hData);
				if ($ptr) {
					$text = FFI::string(FFI::cast("char*", $ptr));
					$k32->GlobalUnlock($hData);
				}
			}
			$u32->CloseClipboard();
		}
		return $text;
	}

	private function handleClipboardInput($key)
	{
		$isCtrl = $this->user32->GetKeyState(0x11) & 0x8000; // VK_CONTROL
		if (!$isCtrl) {
			return false;
		}

		if ($key === ord("C") || $key === ord("c")) {
			// Copy
			$val = "";
			if ($this->currentPage === self::PAGE_LOGIN) {
				if (
					$this->inputFocus === true ||
					$this->inputFocus === "username"
				) {
					$val = $this->loginInput;
				} elseif ($this->inputFocus === "password") {
					// Cannot copy password as per requirement
					return true;
				}
			} elseif (
				$this->currentPage === self::PAGE_PROPERTIES &&
				$this->propActiveField !== ""
			) {
				$val = $this->settings[$this->propActiveField] ?? "";
			} elseif (
				$this->javaModalOpen &&
				$this->javaModalActiveField !== ""
			) {
				$val = $this->settings[$this->javaModalActiveField] ?? "";
			} elseif ($this->bgModalOpen && $this->bgModalActiveField !== "") {
				$val = $this->settings[$this->bgModalActiveField] ?? "";
			} elseif (
				$this->currentPage === self::PAGE_MODS &&
				$this->modSearchFocus
			) {
				$val = $this->modSearchQuery;
			} elseif (
				$this->currentPage === self::PAGE_FOXYCLIENT &&
				$this->foxySubTab === 2 &&
				$this->foxyMacroEditCommandIdx >= 0
			) {
				$keys = array_keys($this->foxyMacroData);
				if ($this->foxyMacroEditCommandIdx < count($keys)) {
					$val = $this->foxyMacroData[$keys[$this->foxyMacroEditCommandIdx]];
				}
			}
			if ($val !== "") {
				$this->copyToClipboard($val);
			}
			return true;
		} elseif ($key === ord("V") || $key === ord("v")) {
			// Paste
			$text = $this->getClipboardText();
			if ($text !== "") {
				if ($this->currentPage === self::PAGE_LOGIN) {
					if (
						$this->inputFocus === true ||
						$this->inputFocus === "username"
					) {
						$this->loginInput .= $text;
					} elseif ($this->inputFocus === "password") {
						$this->loginInputPassword .= $text;
					}
				} elseif (
					$this->currentPage === self::PAGE_PROPERTIES &&
					$this->propActiveField !== ""
				) {
					$this->settings[$this->propActiveField] .= $text;
				} elseif (
					$this->javaModalOpen &&
					$this->javaModalActiveField !== ""
				) {
					$this->settings[$this->javaModalActiveField] .= $text;
				} elseif (
					$this->bgModalOpen &&
					$this->bgModalActiveField !== ""
				) {
					$this->settings[$this->bgModalActiveField] .= $text;
				} elseif (
					$this->currentPage === self::PAGE_MODS &&
					$this->modSearchFocus
				) {
					$this->modSearchQuery .= $text;
					$this->searchModrinth($this->modSearchQuery);
				} elseif (
					$this->currentPage === self::PAGE_FOXYCLIENT &&
					$this->foxySubTab === 2 &&
					$this->foxyMacroEditCommandIdx >= 0
				) {
					$keys = array_keys($this->foxyMacroData);
					if ($this->foxyMacroEditCommandIdx < count($keys)) {
						$this->foxyMacroData[$keys[$this->foxyMacroEditCommandIdx]] .= $text;
						$this->saveFoxyMacros();
					}
				}
			}
			return true;
		} elseif ($key === ord("A") || $key === ord("a")) {
			// Ctrl+A (Select All - for our simplified inputs, we'll just clear and prepare for next input or just do nothing for now since we don't have true selection)
			// But user might expect it to "highlight". Since we don't have highlighting, we'll just leave it or clear.
			// Let's implement "Clear and Focus" if needed, but standard Ctrl+A is usually follow by delete or replace.
			return true;
		}
		return false;
	}

	private function checkModCompatibility()
	{
		if ($this->isCheckingCompat) {
			return;
		}

		$ids = [];
		foreach ($this->tabs as $tab) {
			foreach ($tab["mods"] as $mod) {
				$ids[] = $mod["id"];
				$this->modCompatCache[$mod["id"]] = "checking";
			}
		}
		if (empty($ids)) {
			return;
		}

		$this->isCheckingCompat = true;
		$this->compatChannel = new \parallel\Channel();
		putenv("FOXY_BACKGROUND=1");
		$this->compatProcess = new \parallel\Runtime(__FILE__);
		putenv("FOXY_BACKGROUND=0");

		$mcVersion = $this->config["minecraft_version"];
		$loader = $this->config["loader"];

		$this->compatFuture = $this->compatProcess->run(
			["FoxyCompatCheckJob", "run"],
			[$this->compatChannel, $ids, $mcVersion, $loader],
		);
		$this->pollEvents->addChannel($this->compatChannel);
	}

	private function saveConfig()
	{
		file_put_contents(
			self::DATA_DIR . "/config/mods.json",
			json_encode($this->config, JSON_PRETTY_PRINT),
		);
	}

	private function generateUUID()
	{
		return sprintf(
			"%04x%04x-%04x-%04x-%04x-%04x%04x%04x",
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0x0fff) | 0x4000,
			mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
		);
	}

	private function loadAccounts()
	{
		$accPath = self::DATA_DIR . "/config/accounts.json";
		if (file_exists($accPath)) {
			$data = json_decode(file_get_contents($accPath), true);
			$this->accounts = $data["accounts"] ?? [];
			foreach ($this->accounts as $uuid => &$acc) {
				if (!isset($acc["Type"])) {
					$acc["Type"] = self::ACC_OFFLINE;
				}
			}
			$this->activeAccount = $data["active"] ?? "";
			$this->isLoggedIn = !empty($this->activeAccount);
			$this->accountName =
				$this->accounts[$this->activeAccount]["Username"] ?? "";
			$this->log(
				"Accounts loaded: " .
					count($this->accounts) .
					" accounts found in " .
					realpath($accPath),
			);
		} else {
			// Migration from mods.json
			if (
				isset($this->config["account_name"]) &&
				!empty($this->config["account_name"])
			) {
				$uuid = $this->generateUUID();
				$this->activeAccount = $uuid;
				$name = $this->config["account_name"];
				$this->accountName = $name;
				$this->accounts = [
					$uuid => [
						"Username" => $name,
						"token" => "null_if_not_online_account",
						"Type" => self::ACC_OFFLINE,
					],
				];
				$this->isLoggedIn = true;
				$this->saveAccounts();
			} else {
				$this->accounts = [];
				$this->activeAccount = "";
				$this->accountName = "";
			}
		}
	}

	private function loadSettings()
	{
		$path = self::DATA_DIR . "/config/settings.json";
		if (file_exists($path)) {
			$data = json_decode(file_get_contents($path), true);
			if ($data) {
				$this->settings = array_merge($this->settings, $data);
				$this->log("Settings loaded: " . realpath($path));
			}
		}
	}

	private function saveSettings()
	{
		file_put_contents(
			self::DATA_DIR . "/config/settings.json",
			json_encode($this->settings, JSON_PRETTY_PRINT),
		);
	}

	private function loadModpacks()
	{
		$path = self::DATA_DIR . "/config/modpacks.json";
		if (file_exists($path)) {
			$data = json_decode(file_get_contents($path), true);
			if ($data && is_array($data)) {
				$this->installedModpacks = $data;
				$this->log("Installed modpacks loaded: " . count($data) . " modpacks.");
			}
		}
	}

	private function saveModpacks()
	{
		$dir = self::DATA_DIR . "/config";
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		file_put_contents(
			$dir . "/modpacks.json",
			json_encode($this->installedModpacks, JSON_PRETTY_PRINT),
		);
	}

	private function installModpack($projectId, $projectName)
	{
		if ($this->isInstallingModpack) {
			$this->log("Already installing a modpack. Please wait.", "WARN");
			return;
		}

		$this->isInstallingModpack = true;
		$this->modpackInstallProgress = "Starting modpack install...";
		$this->modpackInstallChannel = new \parallel\Channel();
		$this->modpackInstallProcess = new \parallel\Runtime();

		$version = $this->config["minecraft_version"] ?? "1.20.1";
		$loader = $this->config["loader"] ?? "fabric";
		$cleanVer = str_replace(
			["Fabric ", "Forge ", "Quilt ", "NeoForge "],
			"",
			$version,
		);

		$gameDir = $this->getAbsolutePath($this->settings["game_dir"]);
		$installDir = $gameDir;

		if ($this->settings["separate_modpack_folder"] ?? false) {
			$folderName =
				preg_replace("/[^a-zA-Z0-9_\-]/", "_", $projectName) .
				"_" .
				$cleanVer;
			$installDir =
				$gameDir .
				DIRECTORY_SEPARATOR .
				"versions" .
				DIRECTORY_SEPARATOR .
				$folderName;
			if (!is_dir($installDir)) {
				@mkdir($installDir, 0777, true);
			}
		}

		$modsDir = $installDir . DIRECTORY_SEPARATOR . "mods";
		if (!is_dir($modsDir)) {
			@mkdir($modsDir, 0777, true);
		}

		$cacert = self::CACERT;
		$this->log("Installing modpack: $projectName ($projectId) for $cleanVer $loader");

		$this->modpackInstallFuture = $this->modpackInstallProcess->run(
			function (
				\parallel\Channel $ch,
				$pid,
				$pname,
				$mcver,
				$loader,
				$modsDir,
				$installDir,
				$cacert,
			) {
				$curlFetch = function ($url) use ($cacert, $ch) {
					$curl = curl_init($url);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($curl, CURLOPT_USERAGENT, "FoxyClient/ModpackInstaller");
					curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
					curl_setopt($curl, CURLOPT_TIMEOUT, 30);
					if (file_exists($cacert)) {
						curl_setopt($curl, CURLOPT_CAINFO, $cacert);
					}
					$data = curl_exec($curl);
					$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
					$err = curl_error($curl);
					curl_close($curl);
					
					if ($err) {
						$ch->send(json_encode(["type" => "error", "message" => "Network error: $err"]));
						return [null, 0];
					}
					return [$data, $code];
				};

				// Step 1: Get project info
				$ch->send(json_encode(["type" => "progress", "message" => "Fetching project info..."]));
				[$projData, $projCode] = $curlFetch("https://api.modrinth.com/v2/project/$pid");
				if ($projData === null) return; 
				if ($projCode !== 200) {
					$ch->send(json_encode(["type" => "error", "message" => "Failed to fetch project info (HTTP $projCode)"]));
					$ch->close();
					return;
				}
				$project = json_decode($projData, true);
				$slug = $project["slug"] ?? $pid;
				$iconUrl = $project["icon_url"] ?? "";

				// Step 2: Get compatible versions
				$ch->send(json_encode(["type" => "progress", "message" => "Finding compatible version..."]));
				$params = [
					"loaders" => json_encode([$loader]),
					"game_versions" => json_encode([$mcver]),
				];
				$vUrl = "https://api.modrinth.com/v2/project/$pid/version?" . http_build_query($params);
				[$verData, $verCode] = $curlFetch($vUrl);
				if ($verData === null) return;
				if ($verCode !== 200) {
					$ch->send(json_encode(["type" => "error", "message" => "Failed to fetch versions (HTTP $verCode)"]));
					$ch->close();
					return;
				}
				$versions = json_decode($verData, true);
				if (empty($versions)) {
					$ch->send(json_encode(["type" => "error", "message" => "No compatible version found for $pname on $mcver $loader"]));
					$ch->close();
					return;
				}

				// Find primary mrpack file
				$latestVer = $versions[0];
				$mrpackUrl = "";
				$mrpackFilename = "";
				foreach ($latestVer["files"] as $file) {
					if (str_ends_with($file["filename"], ".mrpack") && ($file["primary"] || !$mrpackUrl)) {
						$mrpackUrl = $file["url"];
						$mrpackFilename = $file["filename"];
					}
				}
				if (!$mrpackUrl) {
					$ch->send(json_encode(["type" => "error", "message" => "No .mrpack file found in release"]));
					$ch->close();
					return;
				}

				// Step 3: Download mrpack
				$ch->send(json_encode(["type" => "progress", "message" => "Downloading $mrpackFilename..."]));
				$tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $mrpackFilename;
				[$mrpackData, $mrpackCode] = $curlFetch($mrpackUrl);
				if ($mrpackData === null) return;
				if ($mrpackCode !== 200) {
					$ch->send(json_encode(["type" => "error", "message" => "Failed to download mrpack (HTTP $mrpackCode)"]));
					$ch->close();
					return;
				}
				file_put_contents($tmpPath, $mrpackData);

				// Step 4: Parse modrinth.index.json from the mrpack (zip)
				$ch->send(json_encode(["type" => "progress", "message" => "Parsing modpack index..."]));
				$zip = new \ZipArchive();
				if ($zip->open($tmpPath) !== true) {
					$ch->send(json_encode(["type" => "error", "message" => "Failed to open mrpack as zip"]));
					@unlink($tmpPath);
					$ch->close();
					return;
				}

				$indexJson = $zip->getFromName("modrinth.index.json");
				if (!$indexJson) {
					$ch->send(json_encode(["type" => "error", "message" => "modrinth.index.json not found in mrpack"]));
					$zip->close();
					@unlink($tmpPath);
					$ch->close();
					return;
				}

				$index = json_decode($indexJson, true);
				if ($index === null) {
					$ch->send(json_encode(["type" => "error", "message" => "Failed to parse index JSON: " . json_last_error_msg()]));
					$zip->close();
					@unlink($tmpPath);
					$ch->close();
					return;
				}
				if (!isset($index["files"])) {
					$ch->send(json_encode(["type" => "error", "message" => "Invalid modrinth.index.json: missing 'files'"]));
					$zip->close();
					@unlink($tmpPath);
					$ch->close();
					return;
				}

				// Step 5: Download all mod files (Concurrent Implementation)
				$ch->send(json_encode(["type" => "progress", "message" => "Checking local files..."]));
				$installedFiles = [];
				$total = count($index["files"]);
				$downloadQueue = [];
				$done = 0;

				foreach ($index["files"] as $modFile) {
					$relPath = $modFile["path"] ?? "";
					$downloads = $modFile["downloads"] ?? [];
					if (!$relPath || empty($downloads)) continue;

					$filename = basename($relPath);
					$subDir = dirname($relPath);
					$actualDir = $installDir . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $subDir);
					if (!is_dir($actualDir)) @mkdir($actualDir, 0777, true);
					$targetPath = $actualDir . DIRECTORY_SEPARATOR . $filename;

					// Pre-check hash
					$expectedHash = $modFile["hashes"]["sha1"] ?? "";
					if (file_exists($targetPath) && $expectedHash && sha1_file($targetPath) === $expectedHash) {
						$installedFiles[] = $relPath;
						$done++;
						continue;
					}

					// Cleanup old versions
					$slugPart = preg_replace('/[-_][\d\.]+.*\.jar$/i', '', $filename);
					if ($slugPart && $subDir === "mods" && is_dir($actualDir)) {
						$slugLower = strtolower($slugPart);
						foreach (scandir($actualDir) as $ef) {
							if ($ef === "." || $ef === ".." || strtolower($ef) === strtolower($filename)) continue;
							if (str_starts_with(strtolower($ef), $slugLower . "-") || str_starts_with(strtolower($ef), $slugLower . "_")) {
								@unlink($actualDir . DIRECTORY_SEPARATOR . $ef);
							}
						}
					}

					$downloadQueue[] = [
						"url" => $downloads[0],
						"path" => $targetPath,
						"filename" => $filename,
						"hash" => $expectedHash,
						"relPath" => $relPath
					];
				}

				if (!empty($downloadQueue)) {
					$mh = curl_multi_init();
					$activeHandles = [];
					$maxConcurrency = 24;
					$queueIdx = 0;

					$addNext = function() use (&$queueIdx, &$downloadQueue, $mh, &$activeHandles, $cacert) {
						if ($queueIdx >= count($downloadQueue)) return false;
						$item = $downloadQueue[$queueIdx++];
						$ch = curl_init($item["url"]);
						$fp = fopen($item["path"], "wb");
						curl_setopt($ch, CURLOPT_FILE, $fp);
						curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
						curl_setopt($ch, CURLOPT_USERAGENT, "FoxyClient/ModpackInstaller");
						curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
						curl_setopt($ch, CURLOPT_TIMEOUT, 60);
						if (file_exists($cacert)) curl_setopt($ch, CURLOPT_CAINFO, $cacert);
						
						curl_multi_add_handle($mh, $ch);
						$activeHandles[(int)$ch] = ["item" => $item, "fp" => $fp, "curl" => $ch];
						return true;
					};

					// Fill initial batch
					for ($i = 0; $i < $maxConcurrency; $i++) {
						if (!$addNext()) break;
					}

					do {
						$mrc = curl_multi_exec($mh, $active);
						if ($active) curl_multi_select($mh, 0.01);

						while ($info = curl_multi_info_read($mh)) {
							$handle = $info["handle"];
							$id = (int)$handle;
							if (!isset($activeHandles[$id])) continue;
							
							$data = $activeHandles[$id];
							fclose($data["fp"]);
							$err = curl_error($handle);
							
							if ($err || curl_getinfo($handle, CURLINFO_HTTP_CODE) !== 200) {
								$msg = "Failed to download {$data["item"]["filename"]}: " . ($err ?: "HTTP " . curl_getinfo($handle, CURLINFO_HTTP_CODE));
								$ch->send(json_encode(["type" => "error", "message" => $msg]));
								// Cleanup other active handles
								foreach ($activeHandles as $ah) {
									@fclose($ah["fp"]);
									curl_multi_remove_handle($mh, $ah["curl"]);
									curl_close($ah["curl"]);
								}
								curl_multi_close($mh);
								$zip->close();
								@unlink($tmpPath);
								return;
							}

							// Verify Hash
							if ($data["item"]["hash"] && sha1_file($data["item"]["path"]) !== $data["item"]["hash"]) {
								$ch->send(json_encode(["type" => "error", "message" => "Hash mismatch for {$data["item"]["filename"]}"]));
								// same cleanup as above... 
								return; 
							}

							$installedFiles[] = $data["item"]["relPath"];
							$done++;
							$ch->send(json_encode([
								"type" => "progress", 
								"message" => "[$done/$total] Filtered: {$data["item"]["filename"]}"
							]));

							curl_multi_remove_handle($mh, $handle);
							curl_close($handle);
							unset($activeHandles[$id]);
							$addNext();
						}
					} while ($active || $queueIdx < count($downloadQueue));
					curl_multi_close($mh);
				}


				// Step 6: Extract overrides (Optimized: Single pass through ZIP index)
				$ch->send(json_encode(["type" => "progress", "message" => "Extracting overrides..."]));
				$overridesDirs = ["overrides/", "client-overrides/"];
				for ($i = 0; $i < $zip->numFiles; $i++) {
					$name = $zip->getNameIndex($i);
					foreach ($overridesDirs as $overridesDir) {
						if (str_starts_with($name, $overridesDir)) {
							$relPath = substr($name, strlen($overridesDir));
							if (!$relPath || str_ends_with($name, "/")) break; // It's just a directory entry
							
							$destPath = $installDir . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $relPath);
							$destDir = dirname($destPath);
							if (!is_dir($destDir)) {
								@mkdir($destDir, 0777, true);
							}
							
							$content = $zip->getFromIndex($i);
							if ($content !== false) {
								file_put_contents($destPath, $content);
								$installedFiles[] = $relPath;
							}
							break; // Found its directory, no need to check other overridesDirs
						}
					}
				}

				$zip->close();
				@unlink($tmpPath);

				// Step 7: Report success with installed file list
				$ch->send(json_encode([
					"type" => "success",
					"slug" => $slug,
					"name" => $pname,
					"version" => $latestVer["version_number"] ?? "unknown",
					"mc_version" => $mcver,
					"loader" => $loader,
					"files" => $installedFiles,
					"install_path" => $installDir, // Pass back the directory used
					"icon_url" => $iconUrl,
					"message" => "Modpack $pname installed successfully!",
				]));
				$ch->close();
			},
			[
				$this->modpackInstallChannel,
				$projectId,
				$projectName,
				$cleanVer,
				$loader,
				$modsDir,
				$installDir, // Use installDir here
				$cacert,
			],
		);

		$this->pollEvents->addChannel($this->modpackInstallChannel);
	}

	private function uninstallModpack($slug)
	{
		if (!isset($this->installedModpacks[$slug])) {
			$this->log("Modpack '$slug' not found in installed list.", "WARN");
			return;
		}

		$pack = $this->installedModpacks[$slug];
		$gameDir = $pack["install_path"] ?? $this->getAbsolutePath($this->settings["game_dir"]);
		$removedCount = 0;

		foreach ($pack["files"] ?? [] as $relPath) {
			$fullPath = $gameDir . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $relPath);
			if (file_exists($fullPath)) {
				@unlink($fullPath);
				$removedCount++;
			}
		}

		$this->log("Uninstalled modpack '$slug': Removed $removedCount files.");
		unset($this->installedModpacks[$slug]);
		$this->saveModpacks();
	}

	private function launchModpack($slug)
	{
		if (!isset($this->installedModpacks[$slug])) {
			return;
		}
		$pack = $this->installedModpacks[$slug];

		// Ensure we have correct version selected for metadata loading (including loader)
		if ($pack["mc_version"]) {
			$loader = strtolower($pack["loader"] ?? "");
			$found = false;
			foreach ($this->versions as $v) {
				if (isset($v["id"])) {
					$vId = $v["id"];
					if (strpos($vId, $pack["mc_version"]) !== false && stripos($vId, $loader) !== false) {
						$this->selectedVersion = $vId;
						$found = true;
						break;
					}
				}
			}
			if (!$found) {
				$this->selectedVersion = $pack["mc_version"];
			}
		}

		$installPath =
			$pack["install_path"] ??
			$this->getAbsolutePath($this->settings["game_dir"]);
		$this->launchGame($installPath);
	}

	private function saveAccounts()
	{
		$data = [
			"active" => $this->activeAccount,
			"accounts" => $this->accounts,
		];
		file_put_contents(
			self::DATA_DIR . "/config/accounts.json",
			json_encode($data, JSON_PRETTY_PRINT),
		);
	}

	public function selectAccount($uuid)
	{
		if (isset($this->accounts[$uuid])) {
			$this->activeAccount = $uuid;
			$this->accountName = $this->accounts[$uuid]["Username"];
			$this->isLoggedIn = true;
			$this->updateDiscordPresence();
		} else {
			$this->logout();
		}
		$this->saveAccounts();
	}

	public function logout()
	{
		$this->activeAccount = "";
		$this->accountName = "";
		$this->isLoggedIn = false;
		$this->currentPage = self::PAGE_LOGIN;
		$this->loginStep = 0;
		$this->saveAccounts();
		$this->updateDiscordPresence();
	}

	private function toggleSelectAll()
	{
		$mods = &$this->tabs[$this->activeTab]["mods"];
		$allChecked = true;
		foreach ($mods as $mod) {
			if (!$mod["checked"]) {
				$allChecked = false;
				break;
			}
		}
		foreach ($mods as &$mod) {
			$mod["checked"] = !$allChecked;
		}
	}

	private function needsModpackUpdate()
	{
		if (!($this->settings["enable_modpack"] ?? false)) {
			return false;
		}

		$loader = $this->config["loader"] ?? "fabric";
		$isFabric =
			($this->selectedVersion &&
				stripos($this->selectedVersion, "fabric") !== false) ||
			$loader === "fabric";

		if (!$isFabric) {
			return false;
		}

		foreach ($this->tabs as $tab) {
			foreach ($tab["mods"] as $mod) {
				if (
					$mod["checked"] &&
					($mod["status"] === "idle" || $mod["status"] === "error")
				) {
					return true;
				}
			}
		}
		return false;
	}

	private function startUpdate()
	{
		if ($this->process) {
			return;
		}

		$mcVerRaw = $this->config["minecraft_version"] ?? "1.21.1";
		$loader = $this->config["loader"] ?? "fabric";

		// Relaxed check: Allow if loader is fabric, regardless of version name
		$isFabric =
			(isset($this->selectedVersion) &&
				stripos($this->selectedVersion, "fabric") !== false) ||
			$loader === "fabric";

		if (!$isFabric) {
			return;
		}

		$mcVerRaw = $this->config["minecraft_version"] ?? "1.21.1";
		$mcVer = $this->getCleanGameVersion($mcVerRaw);
		$loader = $this->config["loader"] ?? "fabric";

		// Collect selected mods from ALL tabs
		$ids = [];
		foreach ($this->tabs as &$tab) {
			foreach ($tab["mods"] as &$mod) {
				if ($mod["checked"]) {
					$ids[] = $mod["id"];
					$mod["status"] = "queued";
				}
			}
		}
		if (empty($ids)) {
			return;
		}

		$this->modChannel = new \parallel\Channel();
		putenv("FOXY_BACKGROUND=1");
		$this->process = new \parallel\Runtime(__FILE__);
		putenv("FOXY_BACKGROUND=0");
		$modsDir =
			$this->getAbsolutePath($this->settings["game_dir"]) .
			DIRECTORY_SEPARATOR .
			"mods";
		if (!is_dir($modsDir)) {
			@mkdir($modsDir, 0777, true);
		}

		$this->modFuture = $this->process->run(
			function (
				\parallel\Channel $ch,
				array $ids,
				string $modsDir,
				string $mcVer,
				string $loader,
			) {
				FoxyModrinthJob::run($ch, $ids, $modsDir, $mcVer, $loader);
			},
			[$this->modChannel, $ids, $modsDir, $mcVer, $loader],
		);
		$this->pollEvents->addChannel($this->modChannel);
	}

	private function getCleanGameVersion($vId)
	{
		// Extracts "1.21.1" from "Fabric 1.21.1" or "Forge 1.21.1"
		if (
			preg_match(
				"/(?:Fabric|Forge|Quilt|NeoForge)\s+([0-9\.]+)/i",
				$vId,
				$m,
			)
		) {
			return $m[1];
		}
		return $vId;
	}

	private function loadFullVersionData($version, $mcDir)
	{
		$versionDir =
			$mcDir .
			DIRECTORY_SEPARATOR .
			"versions" .
			DIRECTORY_SEPARATOR .
			$version;
		$jsonPath = $versionDir . DIRECTORY_SEPARATOR . $version . ".json";

		if (!file_exists($jsonPath)) {
			return null;
		}
		$data = json_decode(file_get_contents($jsonPath), true);
		if (!$data) {
			return null;
		}

		if (isset($data["inheritsFrom"])) {
			$parent = $data["inheritsFrom"];
			$parentData = $this->loadFullVersionData($parent, $mcDir);
			if ($parentData) {
				// Merge libraries (CHILD first, then parent - to ensure child overrides like Fabric/Forge take precedence)
				if (isset($parentData["libraries"])) {
					$data["libraries"] = array_merge(
						$data["libraries"] ?? [],
						$parentData["libraries"],
					);
				}

				// Merge arguments
				if (isset($parentData["arguments"])) {
					if (!isset($data["arguments"])) {
						$data["arguments"] = ["game" => [], "jvm" => []];
					}
					foreach (["game", "jvm"] as $type) {
						$pArgs = $parentData["arguments"][$type] ?? [];
						$cArgs = $data["arguments"][$type] ?? [];
						// Parent first, then child - so child arguments override parent in Java CLI (last one wins)
						$data["arguments"][$type] = array_merge($pArgs, $cArgs);
					}
				} elseif (isset($parentData["minecraftArguments"])) {
					if (!isset($data["minecraftArguments"])) {
						$data["minecraftArguments"] = trim(
							($data["minecraftArguments"] ?? "") .
								" " .
								$parentData["minecraftArguments"],
						);
					}
				}

				// Inherit critical fields if missing
				foreach (
					[
						"mainClass",
						"assetIndex",
						"assets",
						"javaVersion",
						"downloads",
					]
					as $key
				) {
					if (!isset($data[$key]) && isset($parentData[$key])) {
						$data[$key] = $parentData[$key];
					}
				}
			}
		}
		return $data;
	}

	private function verifyAssets($vData, $mcDir)
	{
		$version = $vData["id"];
		// 0. Check Client JAR
		$jarPath =
			$mcDir .
			DIRECTORY_SEPARATOR .
			"versions" .
			DIRECTORY_SEPARATOR .
			$version .
			DIRECTORY_SEPARATOR .
			$version .
			".jar";
		if (!file_exists($jarPath) && isset($vData["inheritsFrom"])) {
			$parent = $vData["inheritsFrom"];
			$jarPath =
				$mcDir .
				DIRECTORY_SEPARATOR .
				"versions" .
				DIRECTORY_SEPARATOR .
				$parent .
				DIRECTORY_SEPARATOR .
				$parent .
				".jar";
		}
		if (!file_exists($jarPath)) {
			return "Missing version JAR: $version.jar";
		}

		// 1. Check Libraries
		if (isset($vData["libraries"])) {
			foreach ($vData["libraries"] as $lib) {
				$libPath = null;
				if (isset($lib["downloads"]["artifact"]["path"])) {
					$libPath =
						$mcDir .
						DIRECTORY_SEPARATOR .
						"libraries" .
						DIRECTORY_SEPARATOR .
						$lib["downloads"]["artifact"]["path"];
				} elseif (isset($lib["name"])) {
					$parts = explode(":", $lib["name"]);
					if (count($parts) >= 3) {
						$group = str_replace(
							".",
							DIRECTORY_SEPARATOR,
							$parts[0],
						);
						$name = $parts[1];
						$libVersion = $parts[2];
						$classifier = $parts[3] ?? "";
						$libPath =
							$mcDir .
							DIRECTORY_SEPARATOR .
							"libraries" .
							DIRECTORY_SEPARATOR .
							$group .
							DIRECTORY_SEPARATOR .
							$name .
							DIRECTORY_SEPARATOR .
							$libVersion .
							DIRECTORY_SEPARATOR .
							$name .
							"-" .
							$libVersion .
							($classifier ? "-$classifier" : "") .
							".jar";
					}
				}
				if ($libPath && !file_exists($libPath)) {
					return "Missing library: " . basename($libPath);
				}
			}
		}

		// 2. Check Asset Index
		$assetId = $vData["assetIndex"]["id"] ?? "legacy";
		$indexPath =
			$mcDir .
			DIRECTORY_SEPARATOR .
			"assets" .
			DIRECTORY_SEPARATOR .
			"indexes" .
			DIRECTORY_SEPARATOR .
			$assetId .
			".json";
		if (!file_exists($indexPath)) {
			return "Missing asset index: $assetId";
		}

		// 3. Fast Asset Check (Check existence of objects)
		$assetData = json_decode(file_get_contents($indexPath), true);
		if ($assetData && isset($assetData["objects"])) {
			$objects = $assetData["objects"];
			// We check local existence for all items. On SSD this is fast.
			foreach ($objects as $name => $obj) {
				$hash = $obj["hash"];
				$prefix = substr($hash, 0, 2);
				$path =
					$mcDir .
					DIRECTORY_SEPARATOR .
					"assets" .
					DIRECTORY_SEPARATOR .
					"objects" .
					DIRECTORY_SEPARATOR .
					$prefix .
					DIRECTORY_SEPARATOR .
					$hash;
				if (!file_exists($path)) {
					return "Missing asset: " . basename($name);
				}
			}
		}

		return true;
	}

	private function checkModpackIcons()
	{
		if ($this->iconDownloadChannel) {
			return; // Already downloading something
		}

		$iconsToDownload = [];
		foreach ($this->installedModpacks as $slug => $pack) {
			if (!empty($pack["icon_url"]) && !isset($this->modpackIconCache[$slug])) {
				$iconsToDownload[$slug] = ["url" => $pack["icon_url"], "is_modpack" => true];
			}
		}

		if (empty($iconsToDownload)) {
			return;
		}

		$this->iconDownloadChannel = new \parallel\Channel();
		$this->pollEvents->addChannel($this->iconDownloadChannel);
		$this->iconCancelChannel = new \parallel\Channel(1);
		$this->iconDownloadProcess = new \parallel\Runtime();
		$cacert = self::CACERT;

		if ($this->iconDownloadFuture) {
			$this->pendingFutures[] = $this->iconDownloadFuture;
		}

		$this->iconDownloadFuture = $this->iconDownloadProcess->run(
			function (\parallel\Channel $ch, \parallel\Channel $cancelCh, $icons, $cacert) {
				$mh = curl_multi_init();
				$handles = [];
				foreach ($icons as $id => $data) {
					$url = is_array($data) ? ($data["url"] ?? "") : $data;
					$curl = curl_init($url);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($curl, CURLOPT_USERAGENT, "FoxyClient/IconFetcher");
					if (file_exists($cacert)) {
						curl_setopt($curl, CURLOPT_CAINFO, $cacert);
					}
					curl_multi_add_handle($mh, $curl);
					$handles[$id] = ["curl" => $curl, "is_modpack" => true];
				}

				if (count($handles) > 0) {
					do {
						$mrc = curl_multi_exec($mh, $active);
					} while ($mrc == CURLM_CALL_MULTI_PERFORM);

					while ($active && $mrc == CURLM_OK) {
						if (curl_multi_select($mh) != -1) {
							do {
								$mrc = curl_multi_exec($mh, $active);
							} while ($mrc == CURLM_CALL_MULTI_PERFORM);
						}
					}

					foreach ($handles as $id => $data) {
						$curl = $data["curl"];
						$content = curl_multi_getcontent($curl);
						if ($content && curl_getinfo($curl, CURLINFO_HTTP_CODE) == 200) {
							$ch->send([
								"type" => "icon_ready",
								"id" => $id,
								"data" => $content,
								"is_modpack" => true,
							]);
						}
						curl_multi_remove_handle($mh, $curl);
						curl_close($curl);
					}
				}
				curl_multi_close($mh);
				$ch->send(["type" => "finished"]);
			},
			[$this->iconDownloadChannel, $this->iconCancelChannel, $iconsToDownload, $cacert]
		);
	}

	private function launchGame($gameDirOverride = null)
	{
		if (
			!$this->selectedVersion ||
			$this->isLaunching ||
			$this->gameProcess
		) {
			return;
		}

		$version = $this->selectedVersion;
		$this->isLaunching = true;
		$this->launchStartTime = microtime(true);
		$this->assetMessage = "PREPARING LAUNCH...";
		
		$baseDir = $this->getAbsolutePath($this->settings["game_dir"]);
		$instanceDir = $gameDirOverride ? $this->getAbsolutePath($gameDirOverride) : $baseDir;

		$vData = $this->loadFullVersionData($version, $baseDir);
		if (!$vData) {
			$this->assetMessage = "FAILED: Could not load version metadata";
			$this->isLaunching = false;
			return;
		}

		// --- NEW: Asset Verification Phase ---
		$this->assetMessage = "VERIFYING VERSION...";
		$verifyRes = $this->verifyAssets($vData, $baseDir);
		if ($verifyRes !== true) {
			$this->assetMessage = "REPAIRING: " . $verifyRes;
			$this->triggerVersionDownload($version, true);
			$this->isLaunching = false;
			return;
		}

		// --- NEW: Modpack Synchronization Phase ---
		if ($this->needsModpackUpdate()) {
			$this->assetMessage = "SYNCING MODPACK...";
			$this->startUpdate();
			// We'll wait for the process to finish in the next frame via pollProcess
			// so we pause launch here
			$this->isLaunching = false;
			$this->shouldAutoLaunchAfterDownload = true; // reusing this for mod sync
			return;
		}

		$activeAcc = $this->accounts[$this->activeAccount] ?? [];
		$accType = $activeAcc["Type"] ?? self::ACC_OFFLINE;

		// Ensure authlib-injector for FoxyClient/Ely.by skins (and Offline accounts)
		if (
			$accType === self::ACC_FOXY ||
			$accType === self::ACC_ELYBY ||
			$accType === self::ACC_OFFLINE
		) {
			$this->assetMessage = "PREPARING SKIN SYSTEM...";
			if (!$this->ensureAuthlibInjector()) {
				if (
					$accType === self::ACC_FOXY ||
					$accType === self::ACC_ELYBY
				) {
					$this->assetMessage =
						"FAILED: Could not download skin system";
					$this->isLaunching = false;
					return;
				}
			}
		}
		// -------------------------------------

		// Get absolute Java path
		$java = $this->settings["java_path"] ?: "java";
		$javaAbs = realpath($java);
		if (!$javaAbs && $java !== "java") {
			$this->assetMessage = "FAILED: Java not found at $java";
			$this->isLaunching = false;
			return;
		}
		$javaExec = $javaAbs ?: $java;

		// Detect Java major version
		$javaVer = 8;
		$out = [];
		@exec("\"$javaExec\" -version 2>&1", $out);
		foreach ($out as $line) {
			if (preg_match('/version "([^"]+)"/', $line, $m)) {
				$verStr = $m[1];
				if (strpos($verStr, "1.8") === 0) {
					$javaVer = 8;
				} else {
					$parts = explode(".", $verStr);
					$javaVer = (int) $parts[0];
				}
				break;
			}
		}

		// 1. Build Classpath (use absolute paths)
		$cp = [];
		if (isset($vData["libraries"])) {
			foreach ($vData["libraries"] as $lib) {
				// --- NEW: Rule Filtering for Libraries ---
				if (isset($lib["rules"])) {
					$allowed = false; // MC standard: disallow by default if rules exist
					foreach ($lib["rules"] as $rule) {
						$match = true;
						if (isset($rule["os"])) {
							if (($rule["os"]["name"] ?? "") !== "windows") {
								$match = false;
							}
						}
						if ($match) {
							$allowed = ($rule["action"] ?? "allow") === "allow";
						}
					}
					if (!$allowed) {
						continue;
					}
				}

				$path = null;
				if (isset($lib["downloads"]["artifact"]["path"])) {
					$path =
						$baseDir .
						DIRECTORY_SEPARATOR .
						"libraries" .
						DIRECTORY_SEPARATOR .
						$lib["downloads"]["artifact"]["path"];
				} elseif (isset($lib["name"])) {
					$parts = explode(":", $lib["name"]);
					if (count($parts) >= 3) {
						$group = str_replace(
							".",
							DIRECTORY_SEPARATOR,
							$parts[0],
						);
						$name = $parts[1];
						$libVersion = $parts[2];
						$classifier = $parts[3] ?? "";
						$path =
							$baseDir .
							DIRECTORY_SEPARATOR .
							"libraries" .
							DIRECTORY_SEPARATOR .
							$group .
							DIRECTORY_SEPARATOR .
							$name .
							DIRECTORY_SEPARATOR .
							$libVersion .
							DIRECTORY_SEPARATOR .
							$name .
							"-" .
							$libVersion .
							($classifier ? "-$classifier" : "") .
							".jar";
					}
				}
				if ($path) {
					if (file_exists($path)) {
						$cp[] = realpath($path);
					}
				}
			}
		}

		// Find and add version jars (Ensuring both loader and vanilla are present if needed)
		$versionJars = [];
		$versionJars[] =
			$baseDir .
			DIRECTORY_SEPARATOR .
			"versions" .
			DIRECTORY_SEPARATOR .
			$version .
			DIRECTORY_SEPARATOR .
			$version .
			".jar";

		if (isset($vData["inheritsFrom"])) {
			$parent = $vData["inheritsFrom"];
			$versionJars[] =
				$baseDir .
				DIRECTORY_SEPARATOR .
				"versions" .
				DIRECTORY_SEPARATOR .
				$parent .
				DIRECTORY_SEPARATOR .
				$parent .
				".jar";
		}

		foreach ($versionJars as $jarPath) {
			if (file_exists($jarPath)) {
				$abs = realpath($jarPath);
				if ($abs && !in_array($abs, $cp)) {
					$cp[] = $abs;
				}
			}
		}

		$cpString = implode(";", $cp);

		// 2. Build Full Command Array (Safe for Windows)
		$cmdArray = [$javaExec];
		$cmdArray[] = "-Xms512M";
		$cmdArray[] = "-Xmx" . $this->settings["ram_mb"] . "M";

		$versionDir =
			$baseDir .
			DIRECTORY_SEPARATOR .
			"versions" .
			DIRECTORY_SEPARATOR .
			$version;
		$nativesDir = realpath($versionDir . DIRECTORY_SEPARATOR . "natives");
		// If no natives folder, try the version dir itself
		if (!$nativesDir) {
			$nativesDir = realpath($versionDir) ?: $versionDir;
		}

		// For inherited versions (Fabric/Forge), ALWAYS use the parent version's natives directory
		// The loader's own folder (e.g. "Fabric 1.21.11") may contain spaces that break Java paths
		if (isset($vData["inheritsFrom"])) {
			$parentDir = $baseDir . DIRECTORY_SEPARATOR . "versions" . DIRECTORY_SEPARATOR . $vData["inheritsFrom"];
			$parentNatives = realpath($parentDir . DIRECTORY_SEPARATOR . "natives");
			if ($parentNatives) {
				$nativesDir = $parentNatives;
			} else {
				$parentAbs = realpath($parentDir);
				if ($parentAbs) {
					$nativesDir = $parentAbs;
				}
			}
		}
		$cmdArray[] = "-Djava.library.path=" . $nativesDir;

		// Global Performance Optimizations
		$cmdArray[] = "-XX:+AlwaysPreTouch";
		$cmdArray[] = "-XX:+DisableExplicitGC";
		$cmdArray[] = "-XX:+PerfDisableSharedMem";

		// GC Optimizer
		switch ($this->settings["jvm_optimizer"] ?? "default") {
			case "g1":
			case "default":
				$cmdArray[] = "-XX:+UnlockExperimentalVMOptions";
				$cmdArray[] = "-XX:+UnlockDiagnosticVMOptions";
				$cmdArray[] = "-XX:+UseG1GC";
				$cmdArray[] = "-XX:G1NewSizePercent=30";
				$cmdArray[] = "-XX:G1MaxNewSizePercent=40";
				$cmdArray[] = "-XX:G1HeapRegionSize=8M";
				$cmdArray[] = "-XX:G1ReservePercent=20";
				$cmdArray[] = "-XX:G1HeapWastePercent=5";
				$cmdArray[] = "-XX:G1MixedGCCountTarget=4";
				$cmdArray[] = "-XX:InitiatingHeapOccupancyPercent=15";
				$cmdArray[] = "-XX:G1MixedGCLiveThresholdPercent=90";
				$cmdArray[] = "-XX:G1RSetUpdatingPauseTimePercent=5";
				$cmdArray[] = "-XX:SurvivorRatio=32";
				$cmdArray[] = "-XX:MaxTenuringThreshold=1";
				break;
			case "zgc":
				$cmdArray[] = "-XX:+UnlockExperimentalVMOptions";
				$cmdArray[] = "-XX:+UseZGC";
				$cmdArray[] = "-XX:ZCollectionInterval=5";
				$cmdArray[] = "-XX:ZAllocationSpikeTolerance=2.0";
				break;
			case "shenandoah":
				$cmdArray[] = "-XX:+UnlockExperimentalVMOptions";
				$cmdArray[] = "-XX:+UseShenandoahGC";
				$cmdArray[] = "-XX:ShenandoahGCHeuristics=compact";
				$cmdArray[] = "-XX:ShenandoahAllocationThreshold=10";
				break;
		}

		if (!empty($this->settings["java_args"])) {
			foreach (explode(" ", $this->settings["java_args"]) as $arg) {
				$arg = trim($arg);
				if ($arg !== "") {
					// Universal Property Sanitization
					if (strpos($arg, "-D") === 0) {
						$arg = preg_replace('/=\s+|\s+=/', '=', $arg);
					}
					$cmdArray[] = $arg;
				}
			}
		}
		// 2.2 Process JVM Arguments from JSON
		$jvmPlaceholders = [
			'${natives_directory}' => $nativesDir,
			'${launcher_name}' => "FoxyClient",
			'${launcher_version}' => self::VERSION,
			'${classpath}' => $cpString,
			'${classpath_separator}' => ";",
			'${library_directory}' => realpath($baseDir . "/libraries"),
		];

		$hasJsonJvmArgs = false;
		if (isset($vData["arguments"]["jvm"])) {
			$hasJsonJvmArgs = true;
			foreach ($vData["arguments"]["jvm"] as $arg) {
				if (is_string($arg)) {
					// Aggressive sanitization for JVM arguments
					$sanitizedArg = trim(strtr($arg, $jvmPlaceholders));

					// Fix for common malformed Fabric JSONs: "-DFabricMcEmu= net.minecraft.client.main.Main"
					// We use regex to catch all variations of spaces around '='
					if (strpos($sanitizedArg, "-D") === 0) {
						$sanitizedArg = preg_replace(
							'/=\s+/',
							"=",
							$sanitizedArg,
						);
						$sanitizedArg = trim($sanitizedArg);
					}

					if (
						$javaVer < 23 &&
						strpos($sanitizedArg, "--sun-misc-unsafe-memory-access") ===
							0
					) {
						continue;
					}
					if (
						$javaVer < 21 &&
						strpos($sanitizedArg, "-XX:+UseCompactObjectHeaders") === 0
					) {
						continue;
					}

					if ($sanitizedArg !== "") {
						$cmdArray[] = $sanitizedArg;
					}
				} elseif (is_array($arg) && isset($arg["rules"])) {
					$allowed = true;
					foreach ($arg["rules"] as $rule) {
						$match = true;
						if (isset($rule["os"])) {
							if (($rule["os"]["name"] ?? "") !== "windows") {
								$match = false;
							}
						}
						if (isset($rule["features"])) {
							$match = false;
						}

						if ($match) {
							if (($rule["action"] ?? "allow") === "disallow") {
								$allowed = false;
							}
						} else {
							if (($rule["action"] ?? "allow") === "allow") {
								$allowed = false;
							}
						}
					}
					if ($allowed && isset($arg["value"])) {
						$values = is_array($arg["value"])
							? $arg["value"]
							: [$arg["value"]];
						foreach ($values as $val) {
							// Filter and sanitize
							$sanitizedVal = trim(strtr($val, $jvmPlaceholders));

							if (strpos($sanitizedVal, "-D") === 0) {
								$sanitizedVal = preg_replace(
									'/=\s+|\s+=/',
									"=",
									$sanitizedVal,
								);
							}

							if (
								$javaVer < 23 &&
								strpos(
									$sanitizedVal,
									"--sun-misc-unsafe-memory-access",
								) === 0
							) {
								continue;
							}
							if (
								$javaVer < 21 &&
								strpos(
									$sanitizedVal,
									"-XX:+UseCompactObjectHeaders",
								) === 0
							) {
								continue;
							}

							if ($sanitizedVal !== "") {
								$cmdArray[] = $sanitizedVal;
							}
						}
					}
				}
			}
		}
		// 2.3 Always apply Foxy branding and Deduplicated Natives path
		$brandingApplied = false;
		$libraryPathApplied = false;
		foreach ($cmdArray as $arg) {
			if (strpos($arg, "-Dminecraft.launcher.brand=") === 0) {
				$brandingApplied = true;
			}
			if (strpos($arg, "-Djava.library.path=") === 0) {
				$libraryPathApplied = true;
			}
		}

		if (!$brandingApplied) {
			$cmdArray[] = "-Dminecraft.launcher.brand=FoxyClient";
			$cmdArray[] = "-Dminecraft.launcher.version=" . self::VERSION;
		}
		if (!$libraryPathApplied) {
			$cmdArray[] = "-Djava.library.path=" . $nativesDir;
		}

		if (!$hasJsonJvmArgs) {
			$cmdArray[] = "-cp";
			$cmdArray[] = $cpString;
		}

		if (
			$accType === self::ACC_FOXY ||
			$accType === self::ACC_ELYBY ||
			$accType === self::ACC_OFFLINE
		) {
			$injectorPath =
				__DIR__ .
				DIRECTORY_SEPARATOR .
				self::CACHE_DIR .
				DIRECTORY_SEPARATOR .
				"authlib-injector.jar";
			if (
				file_exists($injectorPath) &&
				filesize($injectorPath) > 100000
			) {
				$endpoint =
					$accType === self::ACC_FOXY
						? self::FOXY_ENDPOINT
						: self::ELY_ENDPOINT;
				$cmdArray[] = "-javaagent:" . $injectorPath . "=" . $endpoint;
			}
		}

		// 3. Main Class
		$mainClass = $vData["mainClass"] ?? "net.minecraft.client.main.Main";
		$cmdArray[] = $mainClass;

		$activeAcc = $this->accounts[$this->activeAccount] ?? [];
		$token = $activeAcc["AccessToken"] ?? "null";
		$type =
			($activeAcc["Type"] ?? self::ACC_OFFLINE) === self::ACC_OFFLINE
				? "legacy"
				: "mojang";

		$placeholders = [
			'${auth_player_name}' => $this->accountName ?: "Player",
			'${version_name}' => $version,
			'${game_directory}' => realpath($instanceDir),
			'${assets_root}' => realpath($baseDir . "/assets"),
			'${assets_index_name}' => $vData["assetIndex"]["id"] ?? "legacy",
			'${auth_uuid}' => $this->activeAccount ?: "0",
			'${auth_access_token}' => $token,
			'${user_type}' => $type,
			'${version_type}' => $vData["type"] ?? "release",
			'${user_properties}' => "{}",
			'${clientid}' => "FoxyClient",
			'${auth_xuid}' => "0",
		];
		$gameArgsRaw = $vData["minecraftArguments"] ?? "";
		if (isset($vData["arguments"]["game"])) {
			foreach ($vData["arguments"]["game"] as $arg) {
				if (is_string($arg)) {
					$s = trim(strtr($arg, $placeholders));
					if ($s !== "") {
						$cmdArray[] = $s;
					}
				} elseif (is_array($arg)) {
					$allowed = true;
					if (isset($arg["rules"])) {
						foreach ($arg["rules"] as $rule) {
							$match = true;
							if (isset($rule["os"])) {
								if (($rule["os"]["name"] ?? "") !== "windows") {
									$match = false;
								}
							}
							if (isset($rule["features"])) {
								$match = false;
							}

							if ($match) {
								if (
									($rule["action"] ?? "allow") ===
									"disallow"
								) {
									$allowed = false;
								}
							} else {
								if (($rule["action"] ?? "allow") === "allow") {
									$allowed = false;
								}
							}
						}
					}
					if ($allowed && isset($arg["value"])) {
						$vals = is_array($arg["value"])
							? $arg["value"]
							: [$arg["value"]];
						foreach ($vals as $v) {
							$s = trim(strtr($v, $placeholders));
							if ($s !== "") {
								$cmdArray[] = $s;
							}
						}
					}
				}
			}
		} else {
			$gameArgsTranslated = strtr($gameArgsRaw, $placeholders);
			foreach (explode(" ", $gameArgsTranslated) as $arg) {
				$arg = trim($arg);
				if ($arg !== "") {
					$cmdArray[] = $arg;
				}
			}
		}

		if (!empty($this->settings["minecraft_args"])) {
			foreach (explode(" ", $this->settings["minecraft_args"]) as $arg) {
				$arg = trim($arg);
				if ($arg !== "") {
					if (strpos($arg, "-D") === 0) {
						$arg = preg_replace('/=\s+|\s+=/', '=', $arg);
					}
					$cmdArray[] = $arg;
				}
			}
		}

		$this->assetMessage = "STARTING MINECRAFT...";

		$logCmd = implode(" ", $cmdArray);
		$logCmd = preg_replace('/--accessToken\s+[^\s]+/', '--accessToken [REDACTED]', $logCmd);
		$this->log("Launching Game: " . $logCmd);
		
		// Write ALL args to argfile to bypass Windows 8191-char command line limit
		$argfilePath = __DIR__ . DIRECTORY_SEPARATOR . self::CACHE_DIR . DIRECTORY_SEPARATOR . "launch_args.txt";
		
		// First element is java executable, everything else goes into the argfile
		$javaExecCmd = array_shift($cmdArray);
		
		// Write argfile — each argument on its own line
		$argLines = [];
		foreach ($cmdArray as $arg) {
			$arg = trim($arg);
			if ($arg === "") {
				continue;
			}
			// If arg contains spaces and isn't already quoted, wrap in quotes
			// Java argfile parser treats \ as escape char inside quotes, so double them
			if (strpos($arg, " ") !== false && $arg[0] !== '"') {
				$arg = '"' . str_replace('\\', '\\\\', $arg) . '"';
			}
			$argLines[] = $arg;
		}
		file_put_contents($argfilePath, implode("\n", $argLines));
		
		// Build short command: just java @argfile
		$shortCmd = '"' . $javaExecCmd . '" "@' . str_replace('/', '\\', $argfilePath) . '"';
		
		$this->log("Using argfile: " . $argfilePath . " (" . count($cmdArray) . " total args)");
		$this->log("Short command length: " . strlen($shortCmd) . " chars");
		
		// We launch directly, keeping handles open to read stdout/stderr.
		// To prevent UI freezes, we launch using a parallel thread.
		$launchCmd = $shortCmd;
		
		$this->gameChannel = new \parallel\Channel(\parallel\Channel::Infinite);
		$this->gameProcess = new \parallel\Runtime();
		$this->gameProcess->run(function(\parallel\Channel $ch, string $cmd, string $dir) {
			$pipes = [];
			// Merge STDERR into STDOUT via cmd.exe, avoiding dual-pipe deadlocks
			$cmd = $cmd . " 2>&1";
			$proc = proc_open(
				$cmd,
				[
					0 => ["pipe", "r"],
					1 => ["pipe", "w"]
				],
				$pipes,
				$dir,
				null,
				["bypass_shell" => false]
			);

			if (is_resource($proc)) {
				$status = proc_get_status($proc);
				$ch->send(["type" => "pid", "pid" => $status["pid"]]);

				while (!feof($pipes[1])) {
					$line = fgets($pipes[1]);
					if ($line !== false) {
						$line = trim($line);
						if ($line !== "") {
							// All output safely merged to stdout
							$ch->send(["type" => "log", "isError" => false, "msg" => $line]);
						}
					}

					$status = proc_get_status($proc);
					if (!$status["running"] && feof($pipes[1])) {
						$ch->send(["type" => "exit", "code" => $status["exitcode"]]);
						break;
					}
				}

				@fclose($pipes[0]);
				@fclose($pipes[1]);
				@proc_close($proc);
			} else {
				$ch->send(["type" => "error", "msg" => "Failed to start game process."]);
			}
		}, [$this->gameChannel, $launchCmd, $instanceDir]);

		$this->pollEvents->addChannel($this->gameChannel);
		
		$this->isLaunching = false;
		$this->assetMessage = "GAME RUNNING"; 
		$this->gameStartTime = microtime(true);
		$this->updateDiscordPresence();
	}

	private function updateDiscordPresence()
	{
		if (!$this->discord) {
			return;
		}

		$details = "Foxy Launcher";
		$state = "Idle";
		$smallImage = null;
		$smallText = null;

		if ($this->assetMessage === "GAME RUNNING") {
			$details = "Playing Minecraft " . $this->selectedVersion;
			$state = "In-game via FoxyClient";
		} elseif ($this->isLaunching) {
			$details = "Launching Minecraft " . $this->selectedVersion;
			$state = !empty($this->assetMessage) ? $this->assetMessage : "Preparing...";
		} else {
			switch ($this->currentPage) {
				case self::PAGE_HOME:
					$details = "Main Menu";
					break;
				case self::PAGE_ACCOUNTS:
					$details = "Account Manager";
					break;
				case self::PAGE_MODS:
					$details = "Modpack Browser";
					break;
				case self::PAGE_VERSIONS:
					$details = "Version Selector";
					break;
				case self::PAGE_PROPERTIES:
					$details = "Editing Settings";
					break;
				case self::PAGE_LOGIN:
					$details = "Account Login";
					break;
			}
			$state = $this->isLoggedIn
				? "Ready to Launch"
				: "Waiting for Account";
		}

		if ($this->isLoggedIn) {
			$smallText = "Logged in as " . $this->accountName;
			// Map account type to small image key
			$type =
				$this->accounts[$this->activeAccount]["Type"] ??
				self::ACC_OFFLINE;
			$smallImage = match ($type) {
				self::ACC_MICROSOFT => "microsoft_logo",
				self::ACC_FOXY => "foxy_small",
				self::ACC_ELYBY => "elyby_logo",
				default => "steve_head",
			};
		}

		$buttons = [
			["label" => "Visit Website", "url" => "https://foxyclient.qzz.io"],
			[
				"label" => "Join Discord",
				"url" => "https://discord.gg/HhRDbGQHXz",
			],
		];

		$this->discord->updatePresence(
			$details,
			$state,
			"foxy_logo", // largeImage
			"FoxyClient v" . self::VERSION, // largeText
			$smallImage, // smallImage
			$smallText, // smallText
			$buttons, // buttons
		);
	}

	private function pollProcess()
	{
		// Handle Modrinth search debouncing
		if (
			$this->modSearchDebounceTimer > 0 &&
			microtime(true) >= $this->modSearchDebounceTimer
		) {
			$this->modSearchDebounceTimer = 0;
			$this->searchModrinth($this->modSearchQuery);
		}

		// Handle Modrinth page debouncing
		if (
			$this->modPageDebounceTimer > 0 &&
			microtime(true) >= $this->modPageDebounceTimer
		) {
			$this->modPageDebounceTimer = 0;
			$this->searchModrinth($this->modSearchQuery, $this->modPageTarget);
		}

		if (
			!$this->process &&
			!$this->assetProcess &&
			!$this->vManifestProcess &&
			!$this->gameProcess &&
			!$this->isStoppingOverlay &&
			!$this->modrinthProcess &&
			!$this->compatProcess &&
			$this->iconDownloadProcess === null &&
			empty($this->modDownloadRuntimes) &&
			$this->modpackInstallProcess === null &&
			$this->assetMessage !== "GAME RUNNING"
		) {
			return;
		}

		// Cleanup completed Futures to free memory (check every frame, very cheap)
		if (!empty($this->pendingFutures)) {
			$this->pendingFutures = array_filter(
				$this->pendingFutures,
				function ($f) {
					try {
						return !$f->done();
					} catch (\Throwable $e) {
						return false;
					}
				},
			);
		}

		$this->updateOverlay();
		try {
			// Process events with a frame-time budget to prevent UI stutter (max 8ms boundary)
			$pollStartTime = microtime(true);
			$processed = 0;
			while ($processed < 50 && ($event = $this->pollEvents->poll())) {
				$processed++;
				$this->needsRedraw = true; // Any async event might change the UI state
				// Determine source channel and re-add
				$val = $event->value;
				if (is_string($val)) {
					$data = json_decode(trim($val), true);
				} else {
					$data = (array) $val;
				}

				$isMod =
					$this->modChannel &&
					(string) $event->source === (string) $this->modChannel;
				$isAsset =
					$this->assetChannel &&
					(string) $event->source === (string) $this->assetChannel;
				$isManifest =
					$this->vManifestChannel &&
					(string) $event->source ===
						(string) $this->vManifestChannel;
				$isCompat =
					$this->compatChannel &&
					(string) $event->source === (string) $this->compatChannel;
				$isIcon =
					$this->iconDownloadChannel &&
					(string) $event->source ===
						(string) $this->iconDownloadChannel;
				$isModDownload = isset($this->channelToModId[(string)$event->source]);
				$isModpackInstall =
					$this->modpackInstallChannel &&
					(string) $event->source === (string) $this->modpackInstallChannel;
				$isModrinth =
					$this->modrinthChannel &&
					(string) $event->source === (string) $this->modrinthChannel;
				$isUpdate =
					$this->updateChannel &&
					(string) $event->source === (string) $this->updateChannel;
				$isFoxyUpdate =
					$this->foxyUpdateChannel &&
					(string) $event->source === (string) $this->foxyUpdateChannel;
				$isHttp =
					$this->httpResultChannel &&
					(string) $event->source === (string) $this->httpResultChannel;
				$isGameProcess = 
					$this->gameChannel && 
					(string) $event->source === (string) $this->gameChannel;

				if ($isGameProcess) {
					$this->pollEvents->addChannel($this->gameChannel);
					if (isset($data["type"])) {
						if ($data["type"] === "pid") {
							$this->gamePid = (int)$data["pid"];
							$this->log("Game process launched natively in thread (PID: {$this->gamePid}). Watching pipes.");
							
							// Auto-hide launcher
							$this->user32->ShowWindow($this->hwnd, 0); // SW_HIDE
							
							if ($this->settings["overlay_cpu"] || $this->settings["overlay_gpu"] || $this->settings["overlay_ram"] || $this->settings["overlay_vram"]) {
								$this->startOverlayThread($this->gamePid);
							}
						} elseif ($data["type"] === "log") {
							$this->log(($data["isError"] ? "[Game/Stderr] " : "[Game/Stdout] ") . $data["msg"], $data["isError"] ? "WARN" : "INFO");
						} elseif ($data["type"] === "error") {
							$this->log("Failed to start game process.", "ERROR");
							$this->assetMessage = "FAILED TO START";
						} elseif ($data["type"] === "exit") {
							$this->log("Game process detected as STOPPED. Exit code: " . $data["code"]);
							
							// Auto-unhide launcher
							$this->user32->ShowWindow($this->hwnd, 5); // SW_SHOW
							
							$this->gameProcess = null;
							$this->gameChannel = null;
							$this->assetMessage = "GAME CLOSED";
							$this->stopOverlayThread();
							$this->updateDiscordPresence();
						}
					}
				}

				if ($isModDownload) {
					$projectId = $this->channelToModId[(string)$event->source] ?? null;
					if ($projectId && isset($this->modDownloadChannels[$projectId])) {
						$this->pollEvents->addChannel($this->modDownloadChannels[$projectId]);
					}
				}
				if ($isMod) {
					$this->pollEvents->addChannel($this->modChannel);
				}
				if (
					$isModrinth &&
					$val !== null &&
					(isset($data["type"]) &&
						$data["type"] !== "results_finished")
				) {
					$this->pollEvents->addChannel($this->modrinthChannel);
				}
				if ($isAsset) {
					$this->pollEvents->addChannel($this->assetChannel);
				}
				if ($isManifest) {
					$this->pollEvents->addChannel($this->vManifestChannel);
				}
				if ($isUpdate) {
					$this->pollEvents->addChannel($this->updateChannel);
				}
				if ($isFoxyUpdate) {
					$this->pollEvents->addChannel($this->foxyUpdateChannel);
				}
				if ($isCompat) {
					$this->pollEvents->addChannel($this->compatChannel);
				}
				if ($isHttp) {
					$this->pollEvents->addChannel($this->httpResultChannel);
				}
				if ($isFoxyUpdate && $val !== null) {
					$this->foxyModLatestVersion = (string)$val;
					$this->updateFoxyUpdateFlag();
					$this->foxyUpdateProcess = null;
					$this->foxyUpdateChannel = null;
				}
				if ($isIcon) {
					$this->pollEvents->addChannel($this->iconDownloadChannel);
				}

				if ($isModpackInstall) {
					$this->pollEvents->addChannel($this->modpackInstallChannel);
				}

				if ($data) {
					if ($isFoxyUpdate) {
						$this->foxyModLatestVersion = (string)$val;
						$this->updateFoxyUpdateFlag();
						$this->foxyUpdateProcess = null;
						$this->foxyUpdateChannel = null;
					} elseif ($isUpdate && isset($data["type"])) {
						if ($data["type"] === "ca_update_progress") {
							$this->caUpdateProgress = (float) $data["pct"];
						} elseif ($data["type"] === "ca_update_res") {
							$this->isUpdatingCacert = false;
							$this->updateMessage =
								"CA Certificates updated successfully!";
						} elseif ($data["type"] === "ca_update_err") {
							$this->isUpdatingCacert = false;
							$this->updateMessage =
								"Error: " . ($data["msg"] ?? "Unknown");
						} elseif ($data["type"] === "ui_update_res") {
							$this->isCheckingUiUpdate = false;
							$ver = $data["version"];
							$cleanVer = ltrim($ver, 'v');
							$cleanLocal = ltrim(self::VERSION, 'v');
							if (version_compare($cleanVer, $cleanLocal, '>')) {
								$this->hasUiUpdate = true;
								$this->updateMessage = "New version available: $ver (Current: " . self::VERSION . ")";
							} else {
								$this->hasUiUpdate = false;
								$this->updateMessage = "You are running the latest version: " . self::VERSION;
							}
						} elseif ($data["type"] === "ui_update_err") {
							$this->isCheckingUiUpdate = false;
							$this->hasUiUpdate = false;
							$this->updateMessage =
								"Check failed: " . ($data["msg"] ?? "Unknown");
						}
					}
					if ($isHttp && isset($data["type"])) {
						if ($data["type"] === "http_result") {
							$this->httpResults[$data["id"]] = $data;
						} elseif ($data["type"] === "log") {
							$this->log($data["msg"], $data["level"] ?? "INFO");
						}
					}

					if ($isModrinth) {
						$logStr = is_string($val)
							? substr($val, 0, 50)
							: json_encode($data);
						$this->log(
							"Received message from Modrinth channel: " .
								substr($logStr, 0, 50) .
								"...",
						);
					}
					if ($isModrinth && isset($data["type"])) {
						if ($data["type"] === "results") {
							$res = $data["data"]["hits"] ?? [];
							$total = $data["data"]["total_hits"] ?? 0;
							$page = $data["page"] ?? 0;
							$query = $data["query"] ?? "";
							$isPrefetch = $data["isPrefetch"] ?? false;

							// Only process results if they match the CURRENT search query
							if ($query !== $this->modSearchQuery) {
								$this->log(
									"Discarding stale Modrinth results for query: $query (Current: {$this->modSearchQuery})",
								);
								if (!$isPrefetch) {
									$this->isSearchingModrinth = false;
								} else {
									$this->isPrefetching = false;
								}
								continue;
							}

							$this->modrinthTotalHits = $total;
							$this->modrinthResultCache[$page] = $res;

							// PROMOTION: If this was a prefetch but it's for the page we are current on
							// and we ARE marked as searching, promote it!
							if (
								$isPrefetch &&
								$this->isSearchingModrinth &&
								($page === $this->modrinthPage ||
									$page === $this->modrinthPrefetchPage)
							) {
								$this->log(
									"Promoting prefetch results for page $page to main view.",
								);
								$isPrefetch = false;
							}

							if ($isPrefetch) {
								$this->isPrefetching = false;
								$this->log(
									"Prefetched Modrinth page $page ready.",
								);
							} else {
								$this->modrinthSearchResults = $res;
								$this->isSearchingModrinth = false; // Reset state
								$this->log(
									"Modrinth found " .
										count($this->modrinthSearchResults) .
										" results (Total: " .
										$this->modrinthTotalHits .
										")",
								);
							}

							// Dispatch icon downloads
							$iconsToDownload = [];
							foreach ($res as $hit) {
								if (!empty($hit["icon_url"]) && !isset($this->modIconCache[$hit["project_id"]])) {
									$iconsToDownload[$hit["project_id"]] = $hit["icon_url"];
								}
							}
							
							// Also check installed modpacks for icons
							foreach ($this->installedModpacks as $slug => $pack) {
								if (!empty($pack["icon_url"]) && !isset($this->modpackIconCache[$slug])) {
									$iconsToDownload[$slug] = ["url" => $pack["icon_url"], "is_modpack" => true];
								}
							}

							if (!empty($iconsToDownload)) {
								// Signal previous task to STOP if it's still running
								if ($this->iconCancelChannel) {
									try {
										$this->iconCancelChannel->send(true);
									} catch (\Throwable $e) {
									}
								}

								if (!$this->iconDownloadChannel) {
									$this->iconDownloadChannel = new \parallel\Channel();
									$this->pollEvents->addChannel(
										$this->iconDownloadChannel,
									);
								}

								// Buffered(1) cancel channel so send() never blocks the main thread
								$this->iconCancelChannel = new \parallel\Channel(
									1,
								);

								// Fresh runtime each time - a persistent runtime BLOCKS if old task is still running
								$this->iconDownloadProcess = new \parallel\Runtime();

								$cacert = self::CACERT;

								// Push old Future to pending array to prevent blocking destructor
								if ($this->iconDownloadFuture) {
									$this->pendingFutures[] =
										$this->iconDownloadFuture;
								}
								$this->iconDownloadFuture = $this->iconDownloadProcess->run(
									function (
										\parallel\Channel $ch,
										\parallel\Channel $cancelCh,
										$icons,
										$cacert,
									) {
										$mh = curl_multi_init();
										$handles = [];
										foreach ($icons as $id => $data) {
											$url = is_array($data) ? ($data["url"] ?? "") : $data;
											$curl = curl_init($url);
											curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
											curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
											curl_setopt($curl, CURLOPT_USERAGENT, "FoxyClient/IconFetcher");
											if (file_exists($cacert)) {
												curl_setopt($curl, CURLOPT_CAINFO, $cacert);
											}
											curl_multi_add_handle($mh, $curl);
											$handles[$id] = [
												"curl" => $curl, 
												"is_modpack" => is_array($data) && ($data["is_modpack"] ?? false)
											];
										}

										// Helper: non-blocking cancel check (recv throws when empty)
										$isCanceled = function () use (
											$cancelCh,
										) {
											try {
												return (bool) $cancelCh->recv(
													false,
												);
											} catch (\Throwable $e) {
												return false;
											}
										};

										if (count($handles) > 0) {
											do {
												$mrc = curl_multi_exec(
													$mh,
													$active,
												);
											} while (
												$mrc == CURLM_CALL_MULTI_PERFORM
											);

											while (
												$active &&
												$mrc == CURLM_OK
											) {
												if ($isCanceled()) {
													foreach ($handles as $h) {
														curl_close($h["curl"]);
													}
													curl_multi_close($mh);
													return;
												}
												if (
													curl_multi_select(
														$mh,
														0.05,
													) !== -1
												) {
													do {
														$mrc = curl_multi_exec(
															$mh,
															$active,
														);
													} while (
														$mrc ==
														CURLM_CALL_MULTI_PERFORM
													);
												}
											}

											foreach ($handles as $id => $data) {
												if ($isCanceled()) {
													break;
												}

												$curl = $data["curl"];
												$content = curl_multi_getcontent(
													$curl,
												);
												if (
													$content &&
													curl_getinfo(
														$curl,
														CURLINFO_HTTP_CODE,
													) == 200
												) {
													if (
														function_exists(
															"imagecreatefromstring",
														)
													) {
														$img = @imagecreatefromstring(
															$content,
														);
														if ($img) {
															$w = imagesx($img);
															$h = imagesy($img);

															// Downscale to 128px max for performance (icons are rendered at 64x64)
															if (
																$w > 128 ||
																$h > 128
															) {
																$nw = 128;
																$nh = 128;
																if ($w > $h) {
																	$nh =
																		(int) ($h *
																			(128 /
																				$w));
																} else {
																	$nw =
																		(int) ($w *
																			(128 /
																				$h));
																}
																$scaled = imagecreatetruecolor(
																	$nw,
																	$nh,
																);
																imagealphablending(
																	$scaled,
																	false,
																);
																imagesavealpha(
																	$scaled,
																	true,
																);
																imagecopyresampled(
																	$scaled,
																	$img,
																	0,
																	0,
																	0,
																	0,
																	$nw,
																	$nh,
																	$w,
																	$h,
																);
																imagedestroy(
																	$img,
																);
																$img = $scaled;
															} else {
																imagealphablending(
																	$img,
																	false,
																);
																imagesavealpha(
																	$img,
																	true,
																);
															}

															ob_start();
															imagepng(
																$img,
																null,
																0,
															); // Compression level 0 (Fastest)
															$content = ob_get_clean();
															imagedestroy($img);
														}
													}
													$ch->send([
														"type" => "icon_ready",
														"id" => $id,
														"data" => $content,
														"is_modpack" => $data["is_modpack"] ?? false,
													]);
												}
												curl_multi_remove_handle(
													$mh,
													$curl,
												);
												curl_close($curl);
											}
										}
										curl_multi_close($mh);
										$ch->send(["type" => "finished"]);
									},
									[
										$this->iconDownloadChannel,
										$this->iconCancelChannel,
										$iconsToDownload,
										$cacert,
									],
								);
							}
						} else {
							$this->modrinthError =
								$data["message"] ?? "Search error";
						}
						$this->isSearchingModrinth = false;
						$this->modrinthChannel = null;
					}

					if ($isModDownload && isset($data["type"])) {
						$projIdMsg = $this->channelToModId[(string)$event->source] ?? null;
						if ($projIdMsg) {
							if ($data["type"] === "progress") {
								$this->log("[$projIdMsg] " . $data["message"]);
							} elseif ($data["type"] === "progress_pct") {
								$this->modDownloadProgresses[$projIdMsg] = (float)$data["pct"];
							} elseif ($data["type"] === "success") {
								$this->log(
									"[$projIdMsg] " . $data["message"],
									"SUCCESS",
								);
								unset($this->modDownloadProgresses[$projIdMsg]);
								unset($this->modDownloadChannels[$projIdMsg]);
								unset($this->modDownloadRuntimes[$projIdMsg]);
								unset($this->modDownloadFutures[$projIdMsg]);
								unset($this->channelToModId[(string)$event->source]);
								$this->checkLocalMods(); // Refresh statuses
							} elseif ($data["type"] === "error") {
								$this->log(
									"[$projIdMsg] Error: " . $data["message"],
									"ERROR",
								);
								$this->modrinthError = $data["message"];
								unset($this->modDownloadProgresses[$projIdMsg]);
								unset($this->modDownloadChannels[$projIdMsg]);
								unset($this->modDownloadRuntimes[$projIdMsg]);
								unset($this->modDownloadFutures[$projIdMsg]);
								unset($this->channelToModId[(string)$event->source]);
							}
						}
					}

					if ($isModpackInstall && isset($data["type"])) {
						if ($data["type"] === "progress") {
							$this->modpackInstallProgress = $data["message"] ?? "";
							$this->log("[Modpack] " . $data["message"]);
						} elseif ($data["type"] === "success") {
							$this->log("[Modpack] " . $data["message"], "SUCCESS");
							$slug = $data["slug"] ?? "unknown";
							$this->installedModpacks[$slug] = [
								"name" => $data["name"] ?? "Unknown",
								"version" => $data["version"] ?? "unknown",
								"mc_version" => $data["mc_version"] ?? "",
								"loader" => $data["loader"] ?? "",
								"files" => $data["files"] ?? [],
								"install_path" => $data["install_path"] ?? null,
								"icon_url" => $data["icon_url"] ?? "",
							];
							$this->saveModpacks();
							$this->modpackInstallProgress = "Installed successfully!";
							$this->isInstallingModpack = false;
							$this->modpackInstallProcess = null;
							$this->modpackInstallChannel = null;
							$this->checkLocalMods();
						} elseif ($data["type"] === "error") {
							$this->log("[Modpack] Error: " . $data["message"], "ERROR");
							$this->modpackInstallProgress = "Error: " . ($data["message"] ?? "Unknown");
							$this->isInstallingModpack = false;
							$this->modpackInstallProcess = null;
							$this->modpackInstallChannel = null;
						}
					}

					if (isset($data["type"]) && $data["type"] === "manifest") {
						if (isset($data["versions"])) {
							$this->log(
								"Version manifest updated via background job.",
							);
							$this->versions = $data["versions"];
							$this->versionsLoaded = true;
							$this->isFetchingVersions = false;
							$this->filteredVersionsCache = null; // force refresh

							// Save to cache
							$cacheFile =
								self::CACHE_DIR .
								DIRECTORY_SEPARATOR .
								"versions_cache.json";
							file_put_contents(
								$cacheFile,
								json_encode(["versions" => $this->versions]),
							);
						}
					} elseif (
						isset($data["type"]) &&
						$data["type"] === "icon_ready"
					) {
						if (isset($data["data"])) {
							$memData = $data["data"];
							$id = $data["id"];
							$isMp = $data["is_modpack"] ?? false;
							$tex = $this->createTextureFromMemory($memData);
							if ($isMp) {
								$this->modpackIconCache[$id] = $tex;
							} else {
								$this->modIconCache[$id] = $tex;
							}
							$this->modIconAlpha[$id] = 0.0; // Start fade-in animation
							$this->needsRedraw = true;
						}
					} elseif (
						isset($data["type"]) &&
						$data["type"] === "results_finished"
					) {
						if ($isModrinth) {
							$this->modrinthChannel = null;
							$this->isSearchingModrinth = false;
							$this->isPrefetching = false;
							$this->modrinthPrefetchPage = -1;
							$this->log(
								"Modrinth search background task completed and state reset.",
							);

							// Trigger NEXT prefetch strictly after the current one is done
							$query = $this->lastModrinthQuery;
							$page = $this->modrinthPage;
							$totalHits = $this->modrinthTotalHits;

							// Search neighbors (2 ahead/behind strategy)
							foreach (
								[$page + 1, $page - 1, $page + 2, $page - 2]
								as $p
							) {
								if (
									$p >= 0 &&
									!isset($this->modrinthResultCache[$p])
								) {
									// Don't prefetch past the last page
									if (
										$p > $page &&
										$totalHits > 0 &&
										$totalHits <= $p * 20
									) {
										continue;
									}

									$this->searchModrinth($query, $p, true);
									break; // Only start ONE at a time to keep channel clear
								}
							}
						}
					} elseif (
						isset($data["type"]) &&
						$data["type"] === "finished"
					) {
						if ($isIcon) {
							$this->iconDownloadChannel = null;
							$this->log(
								"Icon download background task completed and channel cleaned up.",
							);
						} elseif ($isCompat) {
							$this->compatChannel = null;
							$this->log(
								"Compatibility check background task completed and channel cleaned up.",
							);
						}
					} elseif (
						isset($data["type"]) &&
						$data["type"] === "compat"
					) {
						// Compatibility check result
						if (isset($data["mod"]) && isset($data["result"])) {
							$this->log(
								"Mod compatibility check result for " .
									$data["mod"] .
									": " .
									$data["result"],
							);
							$this->modCompatCache[$data["mod"]] =
								$data["result"];
						}
					} elseif (
						isset($data["type"]) &&
						$data["type"] === "status"
					) {
						if ($isMod && isset($data["mod"])) {
							$this->log(
								"Mod " .
									$data["mod"] .
									" status: " .
									$data["state"] .
									(isset($data["message"])
										? " (" . $data["message"] . ")"
										: ""),
							);
							foreach ($this->tabs as &$tab) {
								foreach ($tab["mods"] as &$mod) {
									if ($mod["id"] === $data["mod"]) {
										$mod["status"] = $data["state"];
										if (isset($data["pct"])) {
											$mod["pct"] = $data["pct"];
										}
										break 2;
									}
								}
							}
						} elseif ($isAsset && isset($data["message"])) {
							$this->log("Asset task: " . $data["message"]);
						}
					} elseif (
						isset($data["type"]) &&
						$data["type"] === "progress"
					) {
						if ($isAsset && isset($data["pct"])) {
							$this->assetProgress = $data["pct"] / 100.0;
							if (isset($data["msg"])) {
								$this->assetMessage = $data["msg"];
								// Log substantive progress updates (e.g. every 25%)
								if (fmod($data["pct"], 25) < 1) {
									$this->log(
										"Asset download progress: " .
											$data["pct"] .
											"%",
									);
								}
							} elseif (isset($data["name"])) {
								$this->assetMessage =
									"Downloading: " .
									substr($data["name"], 0, 20) .
									"...";
							}
						} elseif (
							$isAsset &&
							isset($data["done"]) &&
							$data["done"]
						) {
							$this->log(
								"Asset download completed successfully.",
							);
							$this->assetMessage = "DOWNLOAD COMPLETE";
							$this->assetProgress = 1.0;
							$this->isDownloadingAssets = false;
							$this->assetProcess = null;
							$this->assetChannel = null;

							if ($this->shouldAutoLaunchAfterDownload) {
								$this->shouldAutoLaunchAfterDownload = false;
								$this->launchGame();
							}
						}
					} elseif (
						isset($data["type"]) &&
						$data["type"] === "error"
					) {
						$this->log(
							"Background Error: " .
								($data["message"] ?? "Unknown"),
							"ERROR",
						);
						if ($isAsset) {
							$this->assetMessage =
								"ERROR: " . ($data["message"] ?? "Unknown");
							$this->shouldAutoLaunchAfterDownload = false;
							$this->isDownloadingAssets = false;
							$this->assetProcess = null;
							$this->assetChannel = null;
						} elseif ($isManifest) {
							$this->vManifestError =
								$data["message"] ?? "Failed to load manifest";
							$this->isFetchingVersions = false;
							if ($this->vManifestProcess) {
								$this->vManifestProcess->close();
								$this->vManifestProcess = null;
							}
							$this->vManifestChannel = null;
						}
					} elseif (isset($data["type"]) && $data["type"] === "ui_update_res") {
						$this->isCheckingUiUpdate = false;
						$netVer = $data["version"] ?? "";
						$cleanNet = ltrim($netVer, 'v');
						$cleanLocal = ltrim(self::VERSION, 'v');
						if ($cleanNet && version_compare($cleanNet, $cleanLocal, '>')) {
							$this->updateMessage = "Update Available: " . $netVer . " (You have " . self::VERSION . ")";
						} else {
							$this->updateMessage = "You are up to date! (" . self::VERSION . ")";
						}
					} elseif (isset($data["type"]) && $data["type"] === "ui_update_err") {
						$this->isCheckingUiUpdate = false;
						$this->updateMessage = "Error: " . ($data["msg"] ?? "Check failed");
						$this->log("UI Update check error: " . $this->updateMessage, "ERROR");
					} elseif (isset($data["type"]) && $data["type"] === "ca_update_ok") {
						$this->isUpdatingCacert = false;
						$this->updateMessage = "Successfully updated cacert.pem!";
						$this->log("Successfully downloaded and installed latest cacert.pem");
					} elseif (isset($data["type"]) && $data["type"] === "ca_update_err") {
						$this->isUpdatingCacert = false;
						$this->updateMessage = "Error: " . ($data["msg"] ?? "CA download failed");
						$this->log("CA Update error: " . $this->updateMessage, "ERROR");
					} elseif (isset($data["type"]) && $data["type"] === "ca_update_progress") {
						$this->caUpdateProgress = (float)($data["pct"] ?? 0);
						$this->needsRedraw = true;
					}
				}
				
				// Exit early if we've spent too much time processing events this frame (approx 8ms boundary)
				if (microtime(true) - $pollStartTime > 0.008) {
					break;
				}
			}
		} catch (\parallel\Events\Error\Timeout $e) {
			// Ignore non-blocking timeout
		} catch (\parallel\Events\Error\Closed $e) {
			// Channel was closed by thread finishing
		}
		// Modrinth cleanup - ONLY after results are processed and future is done
		if (
			!$this->isSearchingModrinth &&
			$this->modrinthProcess &&
			$this->modrinthFuture &&
			$this->modrinthFuture->done()
		) {
			$this->modrinthProcess->close();
			$this->modrinthProcess = null;
			$this->modrinthFuture = null;
			$this->modrinthChannel = null;
		}

		// Check if thread futures are done to clean up state
		if ($this->process && $this->modFuture && $this->modFuture->done()) {
			$this->process->close();
			$this->process = null;
			$this->modChannel = null;
			$this->modFuture = null;
			// Mark stuck mods as done
			foreach ($this->tabs as &$tab) {
				foreach ($tab["mods"] as &$mod) {
					if (
						in_array($mod["status"], [
							"queued",
							"updating",
							"downloading",
						])
					) {
						$mod["status"] = "done";
					}
				}
			}

			// --- NEW: Handle auto-launch after mod sync ---
			if ($this->shouldAutoLaunchAfterDownload) {
				$this->shouldAutoLaunchAfterDownload = false;
				$this->launchGame();
			}
		}

		// Overlay Update
		if (
			$this->isStoppingOverlay &&
			$this->overlayFuture &&
			$this->overlayFuture->done()
		) {
			$this->overlayThread = null;
			$this->overlayChannel = null;
			$this->overlayFuture = null;
			$this->isStoppingOverlay = false;
			$this->log("Overlay Thread Cleaned Up (Background).");
		}
		$this->updateOverlay();

		// Compat check cleanup
		if (
			$this->compatProcess &&
			$this->compatFuture &&
			$this->compatFuture->done()
		) {
			$this->compatProcess->close();
			$this->compatProcess = null;
			$this->compatChannel = null;
			$this->compatFuture = null;
			$this->isCheckingCompat = false;
		}

		if (
			$this->assetProcess &&
			$this->assetFuture &&
			$this->assetFuture->done()
		) {
			try {
				$this->assetFuture->value();
			} catch (\Throwable $e) {
				$this->assetMessage =
					"CRASH: " . substr($e->getMessage(), 0, 30);
			}
			$this->assetProcess = null;
			$this->assetChannel = null;
			$this->assetFuture = null;
			$this->isDownloadingAssets = false;
		}
		if (
			$this->vManifestProcess &&
			$this->vManifestFuture &&
			$this->vManifestFuture->done()
		) {
			$this->vManifestProcess = null;
			$this->vManifestFuture = null;
			$this->vManifestChannel = null;
		}

		// Modpack Install Monitoring
		if (
			$this->modpackInstallProcess &&
			$this->modpackInstallFuture &&
			$this->modpackInstallFuture->done()
		) {
			try {
				$this->modpackInstallFuture->value();
			} catch (\Throwable $e) {
				$this->log("Modpack Install Thread CRASHED: " . $e->getMessage(), "ERROR");
				$this->modpackInstallProgress = "Error: Install thread crashed.";
			}
			$this->modpackInstallProcess = null;
			$this->modpackInstallFuture = null;
			$this->modpackInstallChannel = null;
			$this->isInstallingModpack = false;
		}

		// Proactively check for missing modpack icons if on the Installed tab
		if ($this->modpackSubTab === 2) {
			$this->checkModpackIcons();
		}
	}

	private function getOverlayLineCount()
	{
		$lines = 0;
		if ($this->settings["overlay_cpu"]) {
			$lines++;
		}
		if ($this->settings["overlay_gpu"]) {
			$lines++;
		}
		if ($this->settings["overlay_ram"]) {
			$lines++;
		}
		if ($this->settings["overlay_vram"]) {
			$lines++;
		}
		return max(1, $lines);
	}

	private function startOverlayThread($gamePid = null)
	{
		if ($this->overlayThread) {
			return;
		}

		// Don't run overlay if no OSD elements are enabled
		if (!($this->settings["overlay_cpu"] || $this->settings["overlay_gpu"] || 
			  $this->settings["overlay_ram"] || $this->settings["overlay_vram"])) {
			return;
		}

		$this->overlayChannel = new \parallel\Channel(1); // Buffered to prevent blocking main thread
		$this->overlayThread = new \parallel\Runtime();
		$settings = [
			"overlay_cpu" => (bool) $this->settings["overlay_cpu"],
			"overlay_gpu" => (bool) $this->settings["overlay_gpu"],
			"overlay_ram" => (bool) $this->settings["overlay_ram"],
			"overlay_vram" => (bool) $this->settings["overlay_vram"],
			"font_overlay" =>
				(string) ($this->settings["font_overlay"] ?? "Consolas"),
		];

		$logPath = __DIR__ . DIRECTORY_SEPARATOR . self::LATEST_LOG;

		$this->overlayFuture = $this->overlayThread->run(
			function ($channel, $settings, $targetPid, $logPath) {
				$threadLog = function ($msg) use ($logPath) {
					$formatted = sprintf(
						"[%s] [Overlay] %s\n",
						date("Y-m-d H:i:s"),
						$msg,
					);
					echo $formatted;
					@file_put_contents($logPath, $formatted, FILE_APPEND);
				};
				$threadLog(
					"Overlay Thread Started. Target PID: " .
						($targetPid ?? "None"),
				);
				$running = true;

				$t = "
				typedef unsigned int UINT; typedef unsigned long DWORD; typedef DWORD* LPDWORD;
				typedef unsigned short WORD; typedef unsigned char BYTE; typedef long LONG;
				typedef void* HWND; typedef void* HDC; typedef void* HGLRC; typedef void* HINSTANCE;
				typedef void* HMENU; typedef void* LPVOID; typedef char* LPCSTR;
				typedef long long LRESULT; typedef unsigned long long WPARAM;
				typedef long long LPARAM; typedef void* HBRUSH; typedef void* HICON;
				typedef void* HCURSOR; typedef int BOOL; typedef void* HGDIOBJ;
				typedef void* HFONT; typedef void* HMODULE;
				typedef unsigned long long uintptr_t;
				typedef struct { long left; long top; long right; long bottom; } RECT;
				typedef struct { long x; long y; } POINT;
				typedef struct {
					DWORD dwLength; DWORD dwMemoryLoad;
					unsigned long long ullTotalPhys; unsigned long long ullAvailPhys;
					unsigned long long ullTotalPageFile; unsigned long long ullAvailPageFile;
					unsigned long long ullTotalVirtual; unsigned long long ullAvailVirtual;
					unsigned long long ullAvailExtendedVirtual;
				} MEMORYSTATUSEX;
				typedef struct {
					HWND hwnd; UINT message; WPARAM wParam; LPARAM lParam;
					DWORD time; POINT pt;
				} MSG;
				typedef LRESULT (*WNDPROC)(HWND, UINT, WPARAM, LPARAM);
				typedef struct {
					UINT cbSize; UINT style; WNDPROC lpfnWndProc; int cbClsExtra; int cbWndExtra;
					HINSTANCE hInstance; HICON hIcon; HCURSOR hCursor; HBRUSH hbrBackground;
					LPCSTR lpszMenuName; LPCSTR lpszClassName; HICON hIconSm;
				} WNDCLASSEXA;
				typedef struct { long cx; long cy; } SIZE;
				typedef struct { unsigned char BlendOp; unsigned char BlendFlags; unsigned char SourceConstantAlpha; unsigned char AlphaFormat; } BLENDFUNCTION;
				typedef void* PDH_HLOG; typedef void* PDH_HQUERY; typedef void* PDH_HCOUNTER; typedef long PDH_STATUS;
				typedef struct _PDH_FMT_COUNTERVALUE { DWORD CStatus; union { long long longValue; double doubleValue; long long largeValue; char* AnsiStringValue; unsigned short* WideStringValue; }; } PDH_FMT_COUNTERVALUE, *PPDH_FMT_COUNTERVALUE;
				typedef struct _PDH_FMT_COUNTERVALUE_ITEM_A { char* szName; PDH_FMT_COUNTERVALUE FmtValue; } PDH_FMT_COUNTERVALUE_ITEM_A, *PPDH_FMT_COUNTERVALUE_ITEM_A;
				typedef struct tagPIXELFORMATDESCRIPTOR { WORD nSize; WORD nVersion; DWORD dwFlags; BYTE iPixelType; BYTE cColorBits; BYTE cRedBits; BYTE cRedShift; BYTE cGreenBits; BYTE cGreenShift; BYTE cBlueBits; BYTE cBlueShift; BYTE cAlphaBits; BYTE cAlphaShift; BYTE cAccumBits; BYTE cAccumRedBits; BYTE cAccumGreenBits; BYTE cAccumBlueBits; BYTE cAccumAlphaBits; BYTE cDepthBits; BYTE cStencilBits; BYTE cAuxBuffers; BYTE iLayerType; BYTE bReserved; DWORD dwLayerMask; DWORD dwVisibleMask; DWORD dwDamageMask; } PIXELFORMATDESCRIPTOR;
				typedef void* HKEY;
				typedef void* (*PFNWGLGETPROCADDRESS)(const char*);
				typedef unsigned int (*PFNWGLGETGPUIDSAMDPROC)(unsigned int, unsigned int*);
				typedef int (*PFNWGLGETGPUINFOAMDPROC)(unsigned int, int, unsigned int, unsigned int, void*);
			";

				try {
					$k32 = FFI::cdef(
						$t .
							"
				HMODULE GetModuleHandleA(LPCSTR lpModuleName);
				BOOL GetSystemTimes(void *lpIdleTime, void *lpKernelTime, void *lpUserTime);
				BOOL GlobalMemoryStatusEx(MEMORYSTATUSEX *lpBuffer);
				void Sleep(DWORD dwMilliseconds);
				DWORD GetLastError();
			",
						"kernel32.dll",
					);

					$u32 = FFI::cdef(
						$t .
							"
				HDC GetDC(HWND hWnd); int ReleaseDC(HWND hWnd, HDC hDC);
				int GetWindowTextA(HWND hWnd, char *lpString, int nMaxCount);
				BOOL EnumWindows(BOOL (*lpEnumFunc)(HWND, LPARAM), LPARAM lParam);
				BOOL IsWindowVisible(HWND hWnd);
				BOOL IsWindow(HWND hWnd);
				BOOL GetClientRect(HWND hWnd, RECT *lpRect);
				BOOL GetWindowRect(HWND hWnd, RECT *lpRect);
				DWORD GetWindowThreadProcessId(HWND hWnd, void *lpdwProcessId);
				BOOL ClientToScreen(HWND hWnd, POINT *lpPoint);
				HWND CreateWindowExA(DWORD dwExStyle, LPCSTR lpClassName, LPCSTR lpWindowName, DWORD dwStyle, int X, int Y, int nWidth, int nHeight, HWND hWndParent, HMENU hMenu, HINSTANCE hInstance, LPVOID lpParam);
				BOOL DestroyWindow(HWND hWnd);
				BOOL SetLayeredWindowAttributes(HWND hwnd, DWORD crKey, unsigned char bAlpha, DWORD dwFlags);
				BOOL UpdateLayeredWindow(HWND hWnd, HDC hdcDst, POINT *pptDst, SIZE *psize, HDC hdcSrc, POINT *pptSrc, DWORD crKey, BLENDFUNCTION *pblend, DWORD dwFlags);
				uintptr_t SetWindowLongPtrA(HWND hWnd, int nIndex, LPVOID dwNewLong);
				BOOL SetWindowPos(HWND hWnd, HWND hWndInsertAfter, int X, int Y, int cx, int cy, UINT uFlags);
				BOOL ShowWindow(HWND hWnd, int nCmdShow);
				LRESULT DefWindowProcA(HWND hWnd, UINT Msg, WPARAM wParam, LPARAM lParam);
				UINT RegisterClassExA(const WNDCLASSEXA *unnamedParam1);
				BOOL UnregisterClassA(LPCSTR lpClassName, HINSTANCE hInstance);
				BOOL PeekMessageA(MSG *lpMsg, HWND hWnd, UINT wMsgFilterMin, UINT wMsgFilterMax, UINT wRemoveMsg);
				BOOL TranslateMessage(const MSG *lpMsg);
				LRESULT DispatchMessageA(const MSG *lpMsg);
				BOOL UpdateWindow(HWND hWnd);
				int FillRect(HDC hDC, const RECT *lprc, HBRUSH hbr);
				short GetAsyncKeyState(int vKey);
				HWND GetForegroundWindow();
			",
						"user32.dll",
					);

					$g32 = FFI::cdef(
						$t .
							"
				HGDIOBJ SelectObject(HDC hdc, HGDIOBJ h);
				HGDIOBJ GetStockObject(int i);
				HFONT CreateFontA(int cHeight, int cWidth, int nEscapement, int nOrientation, int cWeight, DWORD bItalic, DWORD bUnderline, DWORD bStrikeOut, DWORD iCharSet, DWORD iOutPrecision, DWORD iClipPrecision, DWORD iQuality, DWORD iPitchAndFamily, LPCSTR pszFaceName);
				int SetBkMode(HDC hdc, int mode);
				DWORD SetBkColor(HDC hdc, DWORD color);
				DWORD SetTextColor(HDC hdc, DWORD color);
				BOOL TextOutA(HDC hdc, int x, int y, LPCSTR lpString, int c);
				BOOL DeleteObject(void *ho);
				HBRUSH CreateSolidBrush(DWORD color);
				HDC CreateCompatibleDC(HDC hdc);
				void* CreateCompatibleBitmap(HDC hdc, int cx, int cy);
				BOOL BitBlt(HDC hdcDest, int xDest, int yDest, int w, int h, HDC hdcSrc, int xSrc, int ySrc, DWORD rop);
				BOOL DeleteDC(HDC hdc);
				int ChoosePixelFormat(HDC hdc, const PIXELFORMATDESCRIPTOR* ppfd);
				BOOL SetPixelFormat(HDC hdc, int format, const PIXELFORMATDESCRIPTOR* ppfd);
			",
						"gdi32.dll",
					);

					$pdh = FFI::cdef(
						$t .
							"
				PDH_STATUS PdhOpenQueryA(const char* szDataSource, DWORD dwUserData, PDH_HQUERY* phQuery);
				PDH_STATUS PdhAddCounterA(PDH_HQUERY hQuery, const char* szFullCounterPath, DWORD dwUserData, PDH_HCOUNTER* phCounter);
				PDH_STATUS PdhCollectQueryData(PDH_HQUERY hQuery);
				PDH_STATUS PdhGetFormattedCounterArrayA(PDH_HCOUNTER hCounter, DWORD dwFormat, DWORD* lpdwBufferSize, DWORD* lpdwItemCount, void* ItemBuffer);
				PDH_STATUS PdhCloseQuery(PDH_HQUERY hQuery);
			",
						"pdh.dll",
					);

					$opengl = FFI::cdef(
						$t .
							"
				HGLRC wglCreateContext(HDC hDc);
				BOOL wglMakeCurrent(HDC hDc, HGLRC newContext);
				BOOL wglDeleteContext(HGLRC oldContext);
				void* wglGetProcAddress(const char* name);
				void glGetIntegerv(unsigned int pname, int *data);
				const char* glGetString(unsigned int name);
				unsigned int glGetError();
			",
						"opengl32.dll",
					);

					$adv = FFI::cdef(
						$t .
							"
				long RegOpenKeyExA(HKEY hKey, LPCSTR lpSubKey, DWORD ulOptions, DWORD samDesired, HKEY* phkResult);
				long RegQueryValueExA(HKEY hKey, LPCSTR lpValueName, DWORD* lpReserved, DWORD* lpType, unsigned char* lpData, DWORD* lpcbData);
				long RegCloseKey(HKEY hKey);
			",
						"advapi32.dll",
					);
				} catch (\Throwable $e) {
					$threadLog("FFI Initialization Error: " . $e->getMessage());
					return;
				}

				// 1. Create Overlay Window (Layered, Transparent, Topmost)
				try {
					$classNameStr = "FoxyOverlay_" . uniqid();
					$className = $u32->new(
						"char[" . (strlen($classNameStr) + 1) . "]",
					);
					FFI::memcpy(
						$className,
						$classNameStr,
						strlen($classNameStr),
					);

					$wcex = $u32->new("WNDCLASSEXA");
					$wcex->cbSize = FFI::sizeof($wcex);
					$wcex->style = 0x0003; // CS_HREDRAW | CS_VREDRAW
					// Store callback in a variable within the thread scope to prevent GC
					$wndProcCallback = function ($hwnd, $msg, $wp, $lp) use (
						$u32,
					) {
						return $u32->DefWindowProcA($hwnd, $msg, $wp, $lp);
					};
					$wcex->lpfnWndProc = $wndProcCallback;
					$wcex->hInstance = $k32->GetModuleHandleA(null);
					$wcex->hbrBackground = $g32->GetStockObject(4); // BLACK_BRUSH
					$wcex->lpszClassName = $u32->cast("LPCSTR", $className);
					$u32->RegisterClassExA(FFI::addr($wcex));

					$ovHwnd = $u32->CreateWindowExA(
						0x00080000 | 0x00000020, // WS_EX_LAYERED | WS_EX_TRANSPARENT
						$u32->cast("LPCSTR", $className),
						"Foxy Overlay",
						0x80000000, // WS_POPUP
						0,
						0,
						300,
						200,
						null,
						null,
						$wcex->hInstance,
						null,
					);

					$u32->ShowWindow($ovHwnd, 5); // SW_SHOW
					$u32->UpdateWindow($ovHwnd);
					// 1.5 Set Initial Transparency (Magenta = transparent, 255 alpha content)
					// Use LWA_COLORKEY (0x01) to make magenta perfectly clear
					$u32->SetLayeredWindowAttributes(
						$ovHwnd,
						0xff00ff,
						255,
						0x01,
					);
					$threadLog("Overlay Window Created & Alpha Set: Success");
				} catch (\Throwable $e) {
					$threadLog("Window Creation Error: " . $e->getMessage());
					if ($channel) {
						try {
							$channel->send("done");
						} catch (\Throwable $ex) {
						}
					}
					return;
				}

				$oFont = null;
				$magentaBrush = null;
				$darkBrush = null;
				try {
					$overlayFontName = $settings["font_overlay"] ?? "Consolas";
					$oFont = $g32->CreateFontA(
						18,
						0,
						0,
						0,
						700,
						0,
						0,
						0,
						0,
						0,
						0,
						3,
						0,
						$overlayFontName,
					);
					$threadLog("Overlay Font: $overlayFontName");
					$magentaBrush = $g32->CreateSolidBrush(0xff00ff);
					$darkBrush = $g32->CreateSolidBrush(0x1a1a1a);
					$threadLog("GDI Resources Created.");
				} catch (\Throwable $e) {
					$threadLog(
						"GDI Resource Creation Error: " . $e->getMessage(),
					);
				}
				try {
					// Framebuffer Infrastructure
					$threadLog("Init: Framebuffer variables...");
					$memHdc = null;
					$memBmp = null;
					$curW = 0;
					$curH = 0;

					$threadLog("Init: System Times Ref...");
					$pIdle = $k32->new("long long[1]");
					$pKern = $k32->new("long long[1]");
					$pUser = $k32->new("long long[1]");
					$threadLog("Win32: GetSystemTimes...");
					$k32->GetSystemTimes($pIdle, $pKern, $pUser);

					$cpuPct = 0.0;
					$gpuPct = 0.0;
					$ramUsed = 0;
					$ramTotal = 0;
					$vramUsed = 0;
					$vramTotal = 0;
					$lastMetUpdate = 0.0;
					$gameHwnd = null;

					$threadLog("Init: Title Buffer...");
					$titleBuf = $u32->new("char[256]");
				} catch (\Throwable $e) {
					$threadLog(
						"Buffer Initialization Error: " . $e->getMessage(),
					);
					if ($channel) {
						try {
							$channel->send("done");
						} catch (\Throwable $ex) {
						}
					}
					return;
				}
				try {
					$threadLog("Init: Structs POINT, SIZE, BLENDFUNCTION...");
					$pptDst = $u32->new("POINT");
					$pptDst->x = 0;
					$pptDst->y = 0;
					$psize = $u32->new("SIZE");
					$pptSrc = $u32->new("POINT");
					$pptSrc->x = 0;
					$pptSrc->y = 0;
					$pblend = $u32->new("BLENDFUNCTION");
					$pblend->BlendOp = 0;
					$pblend->BlendFlags = 0;
					$pblend->SourceConstantAlpha = 255;
					$pblend->AlphaFormat = 0;
				} catch (\Throwable $e) {
					$threadLog(
						"Struct Initialization Error: " . $e->getMessage(),
					);
					if ($channel) {
						try {
							$channel->send("done");
						} catch (\Throwable $ex) {
						}
					}
					return;
				}
				$threadLog("Init: parallel\\Events...");
				$events = new \parallel\Events();
				$events->setBlocking(false);
				$events->addChannel($channel);

				// Init PDH & OpenGL for hardware metrics
				$pdhQuery = null;
				$pdhGpu = null;
				$pdhVram = null;
				$bufSize = null;
				$itemCount = null;
				$nullPtr = null;
				$glCtx = null;
				$glHdc = null;
				$glHwnd = null;
				$glSupport = false;
				$glVendor = "";
				$vramTotal = 0;

				if ($settings["overlay_gpu"] || $settings["overlay_vram"]) {
					try {
						$threadLog("Metrics: Initializing PDH...");
						if ($pdh) {
							$pdhQuery = $pdh->new("PDH_HQUERY");
							$pdh->PdhOpenQueryA(null, 0, FFI::addr($pdhQuery));
							if ($settings["overlay_gpu"]) {
								$pdhGpu = $pdh->new("PDH_HCOUNTER");
								$res = $pdh->PdhAddCounterA(
									$pdhQuery,
									"\\GPU Engine(*engtype_3D)\\Utilization Percentage",
									0,
									FFI::addr($pdhGpu),
								);
								if ($res != 0) {
									$threadLog(
										"PDH Error (GPU): " . dechex($res),
									);
									$pdhGpu = null;
								}
							}
							if ($settings["overlay_vram"]) {
								$pdhVram = $pdh->new("PDH_HCOUNTER");
								$res = $pdh->PdhAddCounterA(
									$pdhQuery,
									"\\GPU Adapter Memory(*)\\Dedicated Usage",
									0,
									FFI::addr($pdhVram),
								);
								if ($res != 0) {
									$threadLog(
										"PDH Error (VRAM): " . dechex($res),
									);
									$pdhVram = null;
								}
							}
							$pdh->PdhCollectQueryData($pdhQuery);
							$bufSize = $pdh->new("DWORD");
							$itemCount = $pdh->new("DWORD");
							$nullPtr = $pdh->cast("void*", 0);
						}

						$threadLog(
							"Metrics: Initializing OpenGL Context for VRAM...",
						);
						if ($opengl) {
							$glHwnd = $u32->CreateWindowExA(
								0,
								"STATIC",
								"FoxyGL",
								0,
								0,
								0,
								1,
								1,
								null,
								null,
								null,
								null,
							);
							if ($glHwnd) {
								$glHdc = $u32->GetDC($glHwnd);
								$pfd = $g32->new("PIXELFORMATDESCRIPTOR");
								$pfd->nSize = FFI::sizeof($pfd);
								$pfd->nVersion = 1;
								$pfd->dwFlags = 0x24; // PFD_SUPPORT_OPENGL | PFD_DRAW_TO_WINDOW
								$pfd->iPixelType = 0;
								$pfd->cColorBits = 32;
								$fmt = $g32->ChoosePixelFormat(
									$glHdc,
									FFI::addr($pfd),
								);
								$g32->SetPixelFormat(
									$glHdc,
									$fmt,
									FFI::addr($pfd),
								);
								$glCtx = $opengl->wglCreateContext($glHdc);
								if (
									$glCtx &&
									$opengl->wglMakeCurrent($glHdc, $glCtx)
								) {
									$glVendor = (string) $opengl->glGetString(
										0x1f00,
									);
									$glExt = (string) $opengl->glGetString(
										0x1f03,
									);
									if (
										strpos($glExt, "GL_ATI_meminfo") !==
											false ||
										strpos(
											$glExt,
											"GL_NVX_gpu_memory_info",
										) !== false
									) {
										$glSupport = true;
									}
									$threadLog(
										"OpenGL Metrics Active: $glVendor - Support: " .
											($glSupport ? "Yes" : "No"),
									);

									// Get Total VRAM via OpenGL if supported (NVIDIA)
									if (
										strpos($glVendor, "NVIDIA") !== false &&
										strpos(
											$glExt,
											"GL_NVX_gpu_memory_info",
										) !== false
									) {
										$glData = $opengl->new("int[4]");
										$opengl->glGetIntegerv(0x9047, $glData); // TOTAL_DEDICATED_VIDMEM_NVX
										if ($glData[0] > 0) {
											$vramTotal =
												(int) ($glData[0] / 1024);
											$threadLog(
												"Total VRAM (OpenGL NVX): $vramTotal MB",
											);
										}
									}

									// Get Total VRAM via OpenGL if supported (AMD)
									if (
										$vramTotal <= 0 &&
										(strpos($glVendor, "AMD") !== false ||
											strpos($glVendor, "ATI") !== false)
									) {
										$pGetGpuIDs = $opengl->wglGetProcAddress(
											"wglGetGPUIDsAMD",
										);
										$pGetGpuInfo = $opengl->wglGetProcAddress(
											"wglGetGPUInfoAMD",
										);

										if ($pGetGpuIDs && $pGetGpuInfo) {
											try {
												$wglGetGPUIDs = $opengl->cast(
													"PFNWGLGETGPUIDSAMDPROC",
													$pGetGpuIDs,
												);
												$wglGetGPUInfo = $opengl->cast(
													"PFNWGLGETGPUINFOAMDPROC",
													$pGetGpuInfo,
												);
												$ids = $opengl->new(
													"unsigned int[32]",
												);
												$count = (int) $wglGetGPUIDs(
													32,
													$ids,
												);
												if ($count > 0) {
													$gpuId = (int) $ids[0];
													$ramMB = $opengl->new(
														"unsigned int",
													);
													// WGL_GPU_RAM_AMD=0x21A3, GL_UNSIGNED_INT=0x1405
													$res = $wglGetGPUInfo(
														$gpuId,
														0x21a3,
														0x1405,
														1,
														FFI::addr($ramMB),
													);
													if ($res > 0) {
														$vramTotal =
															(int) $ramMB->cdata;
														$threadLog(
															"Total VRAM (WGL AMD): $vramTotal MB",
														);
													} else {
														$threadLog(
															"AMD WGL: GPU Info Query failed (ID: $gpuId, Res: $res)",
														);
														// Try vendor string just to verify the call works
														$vBuf = $opengl->new(
															"char[256]",
														);
														if (
															$wglGetGPUInfo(
																$gpuId,
																0x21a2,
																0,
																256,
																$vBuf,
															) > 0
														) {
															$threadLog(
																"AMD WGL Vendor String: " .
																	FFI::string(
																		$vBuf,
																	),
															);
														}
													}
												}
											} catch (\Throwable $e) {
												$threadLog(
													"AMD WGL Error: " .
														$e->getMessage(),
												);
											}
										}
									}
								}
							}
						}

						if ($settings["overlay_vram"] && $vramTotal <= 0) {
							try {
								$threadLog(
									"VRAM: OpenGL Total Query failed or not supported. Falling back to Registry...",
								);
								// HKEY_LOCAL_MACHINE (0x80000002)
								$hklm = $adv->cast("HKEY", -2147483646);
								$guid =
									"{4d36e968-e325-11ce-bfc1-08002be10318}";
								for ($i = 0; $i < 6; $i++) {
									$subKey =
										"SYSTEM\\CurrentControlSet\\Control\\Class\\$guid\\" .
										sprintf("%04d", $i);
									$hkResult = $adv->new("HKEY");
									if (
										$adv->RegOpenKeyExA(
											$hklm,
											$subKey,
											0,
											0x20019,
											FFI::addr($hkResult),
										) == 0
									) {
										$vType = $adv->new("DWORD");
										$vLen = $adv->new("DWORD");
										$vLen->cdata = 8;
										$vData = $adv->new(
											"unsigned long long",
										);
										if (
											$adv->RegQueryValueExA(
												$hkResult,
												"HardwareInformation.qwMemorySize",
												null,
												FFI::addr($vType),
												$adv->cast(
													"unsigned char*",
													FFI::addr($vData),
												),
												FFI::addr($vLen),
											) == 0
										) {
											if ($vData->cdata > 1024 * 1024) {
												$vramTotal =
													(int) ($vData->cdata /
														(1024 * 1024));
												$threadLog(
													"Total VRAM (Native Registry): $vramTotal MB",
												);
												$adv->RegCloseKey($hkResult);
												break;
											}
										}
										$adv->RegCloseKey($hkResult);
									}
								}
							} catch (\Throwable $e) {
								$threadLog(
									"Registry VRAM Error: " . $e->getMessage(),
								);
							}

							if ($vramTotal <= 0) {
								$threadLog(
									"VRAM: Native detection failed. Defaulting to 0MB.",
								);
								$vramTotal = 0;
							}
						}
					} catch (\Throwable $e) {
						$threadLog("Metrics Init Error: " . $e->getMessage());
					}
				}

				$threadLog("Entering Main Execution Loop...");
				$loopCount = 0;
				try {
					while ($running) {
						$loopCount++;

						// 1. MUST-DO: Win32 Message Pump (Keeps window "Responding")
						// Moving this to head of loop for maximum responsiveness
						$msg = $u32->new("MSG");
						while (
							$u32->PeekMessageA(FFI::addr($msg), null, 0, 0, 1)
						) {
							$u32->TranslateMessage(FFI::addr($msg));
							$u32->DispatchMessageA(FFI::addr($msg));
						}

						try {
							$ev = $events->poll();
							if ($ev) {
								$events->addChannel($channel);
								if ($ev->value === "shutdown") {
									$running = false;
									break;
								}
								if (is_array($ev->value)) {
									$oldFontOverlay =
										$settings["font_overlay"] ?? "Consolas";
									$settings = array_merge(
										$settings,
										$ev->value,
									);
									// Recreate overlay font if font_overlay changed
									$newFontOverlay =
										$settings["font_overlay"] ?? "Consolas";
									if ($newFontOverlay !== $oldFontOverlay) {
										if ($oFont) {
											$g32->DeleteObject($oFont);
										}
										$oFont = $g32->CreateFontA(
											18,
											0,
											0,
											0,
											700,
											0,
											0,
											0,
											0,
											0,
											0,
											3,
											0,
											$newFontOverlay,
										);
										$threadLog(
											"Overlay Font Changed: $newFontOverlay",
										);
									}
								}
							}
						} catch (\parallel\Events\Error\Timeout $e) {
						}

						if (!$gameHwnd || !$u32->IsWindow($gameHwnd)) {
							$newGameHwnd = null;
							$u32->EnumWindows(function ($hwnd, $lp) use (
								$u32,
								$targetPid,
								$titleBuf,
								&$newGameHwnd,
							) {
								$pidPtr = $u32->new("DWORD[1]");
								$u32->GetWindowThreadProcessId($hwnd, $pidPtr);
								$wPid = $pidPtr[0];

								// Priority match: The exact PID we were given
								if ($targetPid && $wPid == $targetPid && $u32->IsWindowVisible($hwnd)) {
									$newGameHwnd = $hwnd;
									return false;
								}

								// Fallback match: specific window title check
								$len = $u32->GetWindowTextA(
									$hwnd,
									$titleBuf,
									256,
								);
								if (
									$len > 0 &&
									stripos(
										FFI::string($titleBuf, $len),
										"Minecraft",
									) !== false &&
									$u32->IsWindowVisible($hwnd) &&
									(preg_match('/1\.\d+/', FFI::string($titleBuf, $len)) || 
									 stripos(FFI::string($titleBuf, $len), "Fabric") !== false || 
									 stripos(FFI::string($titleBuf, $len), "Forge") !== false)
								) {
									$newGameHwnd = $hwnd;
									return false;
								}
								return true;
							}, 0);
							$gameHwnd = $newGameHwnd;
						}

						if ($gameHwnd && $u32->IsWindow($gameHwnd)) {
							try {
								// 2. Sync Overlay Window Position
								$cRect = $u32->new("RECT");
								$u32->GetClientRect(
									$gameHwnd,
									FFI::addr($cRect),
								);
								$pt = $u32->new("POINT");
								$pt->x = 0;
								$pt->y = 0;
								$u32->ClientToScreen($gameHwnd, FFI::addr($pt));

								// Update overlay position/size trackers
								if (
									$pptDst->x !== $pt->x ||
									$pptDst->y !== $pt->y ||
									$psize->cx !== $cRect->right ||
									$psize->cy !== $cRect->bottom
								) {
									$pptDst->x = $pt->x;
									$pptDst->y = $pt->y;
									$psize->cx = $cRect->right;
									$psize->cy = $cRect->bottom;
									$needsPosUpdate = true;
								}
							} catch (\Throwable $e) {
								$threadLog(
									"Overlay sync Error: " . $e->getMessage(),
								);
							}

							$nowFrame = microtime(true);
							if (
								!isset($lastFrameUpdate) ||
								$nowFrame - $lastFrameUpdate >= 0.016
							) {
								$lastFrameUpdate = $nowFrame;
								// 3. Update Metrics (Throttle)
								$now = microtime(true);
								if ($now - $lastMetUpdate >= 1.0) {
									$lastMetUpdate = $now;
									try {
										$cI = $k32->new("long long[1]");
										$cK = $k32->new("long long[1]");
										$cU = $k32->new("long long[1]");
										$k32->GetSystemTimes($cI, $cK, $cU);
										$dI = $cI[0] - $pIdle[0];
										$dT =
											$cK[0] -
											$pKern[0] +
											($cU[0] - $pUser[0]);
										if ($dT > 0) {
											$cpuPct = (1.0 - $dI / $dT) * 100.0;
										}
										$pIdle[0] = $cI[0];
										$pKern[0] = $cK[0];
										$pUser[0] = $cU[0];
										if (!$running) {
											break;
										}

										if (
											$settings["overlay_gpu"] ||
											$settings["overlay_vram"]
										) {
											if (!$running) {
												break;
											}
											$vramHandled = false;
											if (
												$glSupport &&
												$opengl &&
												$glCtx
											) {
												try {
													$opengl->wglMakeCurrent(
														$glHdc,
														$glCtx,
													);
													$glData = $opengl->new(
														"int[4]",
													);
													if (
														strpos(
															$glVendor,
															"ATI",
														) !== false ||
														strpos(
															$glVendor,
															"AMD",
														) !== false
													) {
														// On AMD, ATI_meminfo only shows free memory in the current context/process.
														// We strictly use PDH for 'Used' VRAM to get global system usage.
														$vramHandled = false;
													} elseif (
														strpos(
															$glVendor,
															"NVIDIA",
														) !== false
													) {
														$glData = $opengl->new(
															"int[4]",
														);
														$opengl->glGetIntegerv(
															0x9047,
															$glData,
														); // TOTAL_DEDICATED_VIDMEM_NVX
														$vramTotal =
															(int) ($glData[0] /
																1024);
														$opengl->glGetIntegerv(
															0x9049,
															$glData,
														); // CURRENT_AVAILABLE_VIDMEM_NVX
														$vramUsed = max(
															0,
															$vramTotal -
																(int) ($glData[0] /
																	1024),
														);
														$vramHandled = true;
													}
												} catch (\Throwable $e) {
													$threadLog(
														"GL Metrics Loop Error: " .
															$e->getMessage(),
													);
												}
											}

											if ($pdh && $pdhQuery) {
												$pdh->PdhCollectQueryData(
													$pdhQuery,
												);
												if (
													$settings["overlay_gpu"] &&
													$pdhGpu
												) {
													$bufSize->cdata = 0;
													$itemCount->cdata = 0;
													$pdh->PdhGetFormattedCounterArrayA(
														$pdhGpu,
														0x00000200,
														FFI::addr($bufSize),
														FFI::addr($itemCount),
														$nullPtr,
													);
													if ($bufSize->cdata > 0) {
														try {
															$buf = $pdh->new(
																"char[" .
																	$bufSize->cdata .
																	"]",
															);
															$pdh->PdhGetFormattedCounterArrayA(
																$pdhGpu,
																0x00000200,
																FFI::addr(
																	$bufSize,
																),
																FFI::addr(
																	$itemCount,
																),
																FFI::addr(
																	$buf[0],
																),
															);
															$arr = $pdh->cast(
																"PDH_FMT_COUNTERVALUE_ITEM_A*",
																FFI::addr(
																	$buf[0],
																),
															);
															$tot = 0;
															for (
																$i = 0;
																$i <
																$itemCount->cdata;
																$i++
															) {
																if (
																	$arr[$i]
																		->FmtValue
																		->CStatus ==
																	0
																) {
																	$tot +=
																		$arr[$i]
																			->FmtValue
																			->doubleValue;
																}
															}
															$gpuPct = min(
																100.0,
																$tot,
															);
														} catch (\Throwable $e) {
														}
													}
												}
												if (
													$settings["overlay_vram"] &&
													$pdhVram &&
													!$vramHandled
												) {
													$bufSize->cdata = 0;
													$itemCount->cdata = 0;
													$pdh->PdhGetFormattedCounterArrayA(
														$pdhVram,
														0x00000200,
														FFI::addr($bufSize),
														FFI::addr($itemCount),
														$nullPtr,
													);
													if ($bufSize->cdata > 0) {
														try {
															$buf = $pdh->new(
																"char[" .
																	$bufSize->cdata .
																	"]",
															);
															$pdh->PdhGetFormattedCounterArrayA(
																$pdhVram,
																0x00000200,
																FFI::addr(
																	$bufSize,
																),
																FFI::addr(
																	$itemCount,
																),
																FFI::addr(
																	$buf[0],
																),
															);
															$arr = $pdh->cast(
																"PDH_FMT_COUNTERVALUE_ITEM_A*",
																FFI::addr(
																	$buf[0],
																),
															);
															$tot = 0;
															$debugNames = [];
															for (
																$i = 0;
																$i <
																$itemCount->cdata;
																$i++
															) {
																if (
																	$arr[$i]
																		->FmtValue
																		->CStatus ==
																	0
																) {
																	$v =
																		$arr[$i]
																			->FmtValue
																			->doubleValue;
																	$tot += $v;
																	if (
																		$v >
																		1024 *
																			1024 *
																			10
																	) {
																		$debugNames[] =
																			FFI::string(
																				$arr[
																					$i
																				]
																					->szName,
																			) .
																			":" .
																			round(
																				$v /
																					1048576,
																				1,
																			);
																	}
																}
															}
															$vramUsed =
																(int) ($tot /
																	(1024 *
																		1024));
														} catch (\Throwable $e) {
															$threadLog(
																"PDH VRAM Error: " .
																	$e->getMessage(),
															);
														}
													}
												}
											}
										}

										$mem = $k32->new("MEMORYSTATUSEX");
										$mem->dwLength = FFI::sizeof($mem);
										$k32->GlobalMemoryStatusEx(
											FFI::addr($mem),
										);
										$ramTotal =
											(int) ($mem->ullTotalPhys /
												(1024 * 1024));
										$ramUsed =
											$ramTotal -
											(int) ($mem->ullAvailPhys /
												(1024 * 1024));
									} catch (\Throwable $e) {
										$threadLog(
											"Metrics Update Error: " .
												$e->getMessage(),
										);
									}
								}

								// 4. Render to Overlay Window (Framebuffer approach)
								$lc = 0;
								if ($settings["overlay_cpu"]) {
									$lc++;
								}
								if ($settings["overlay_gpu"]) {
									$lc++;
								}
								if ($settings["overlay_vram"]) {
									$lc++;
								}
								if ($settings["overlay_ram"]) {
									$lc++;
								}
								if ($lc > 0) {
									try {
										$hdc = $u32->GetDC($ovHwnd);
										if ($hdc) {
											$oW = 240;
											$oH = $lc * 25 + 10;
											$wLimit = $cRect->right;
											$hLimit = $cRect->bottom;

											// Recreate framebuffer bitmap if size changes
											if (
												!$memHdc ||
												$wLimit != $curW ||
												$hLimit != $curH
											) {
												if ($memBmp) {
													$g32->DeleteObject($memBmp);
												}
												if ($memHdc) {
													$g32->DeleteDC($memHdc);
												}

												$screenDC = $u32->GetDC(null); // Use Screen DC for Layered Window Compatibility
												$memHdc = $g32->CreateCompatibleDC(
													$screenDC,
												);
												$memBmp = $g32->CreateCompatibleBitmap(
													$screenDC,
													$wLimit,
													$hLimit,
												);
												$g32->SelectObject(
													$memHdc,
													$memBmp,
												);
												$u32->ReleaseDC(
													null,
													$screenDC,
												);
												$curW = $wLimit;
												$curH = $hLimit;
											}

											// Clear background of framebuffer with transparency key (Magenta)
											$cRect->left = 0;
											$cRect->top = 0;
											$cRect->right = $curW;
											$cRect->bottom = $curH;
											$u32->FillRect(
												$memHdc,
												FFI::addr($cRect),
												$magentaBrush,
											);

											// Draw to framebuffer
											// (Dark background rectangle removed for 100% transparency)

											// Draw Text (Yellow)
											$oldF = $oFont
												? $g32->SelectObject(
													$memHdc,
													$oFont,
												)
												: null;
											$g32->SetBkMode($memHdc, 1); // TRANSPARENT
											$y = 16;
											$metrics = [];
											if ($settings["overlay_cpu"]) {
												$metrics[] = sprintf(
													" CPU: %.0f%% ",
													$cpuPct,
												);
											}
											if ($settings["overlay_gpu"]) {
												$metrics[] = sprintf(
													" GPU: %.0f%% ",
													$gpuPct,
												);
											}
											if ($settings["overlay_vram"]) {
												$metrics[] = sprintf(
													" VRAM: %d/%d MB ",
													$vramUsed,
													$vramTotal,
												);
											}
											if ($settings["overlay_ram"]) {
												$metrics[] = sprintf(
													" RAM: %d/%d MB ",
													$ramUsed,
													$ramTotal,
												);
											}

											foreach ($metrics as $txt) {
												$len = strlen($txt);
												// Draw Black Outline (4-pass)
												$g32->SetTextColor(
													$memHdc,
													0x000000,
												);
												$g32->TextOutA(
													$memHdc,
													16 - 1,
													$y,
													$txt,
													$len,
												);
												$g32->TextOutA(
													$memHdc,
													16 + 1,
													$y,
													$txt,
													$len,
												);
												$g32->TextOutA(
													$memHdc,
													16,
													$y - 1,
													$txt,
													$len,
												);
												$g32->TextOutA(
													$memHdc,
													16,
													$y + 1,
													$txt,
													$len,
												);
												// Draw Main Yellow Text
												$g32->SetTextColor(
													$memHdc,
													0xffff33,
												);
												$g32->TextOutA(
													$memHdc,
													16,
													$y,
													$txt,
													$len,
												);
												$y += 25;
											}

											if ($oldF) {
												$g32->SelectObject(
													$memHdc,
													$oldF,
												);
											}

											// 5. Transfer to Window DC (BitBlt combined with Layered Window Attributes)
											$g32->BitBlt(
												$hdc,
												0,
												0,
												$curW,
												$curH,
												$memHdc,
												0,
												0,
												0x00cc0020,
											); // SRCCOPY

											$u32->ReleaseDC($ovHwnd, $hdc);
										}
									} catch (\Throwable $e) {
										$threadLog(
											"Rendering Error: " .
												$e->getMessage(),
										);
									}
								} // End of render items if ($lc > 0)
							} // End of 16ms render throttle

							// Show overlay only when game is foreground
							$fgWnd = $u32->GetForegroundWindow();
							$fgPidPtr = $u32->new("DWORD[1]");
							$u32->GetWindowThreadProcessId($fgWnd, $fgPidPtr);
							$gamePidPtr = $u32->new("DWORD[1]");
							$u32->GetWindowThreadProcessId(
								$gameHwnd,
								$gamePidPtr,
							);
							$gameIsForeground = $fgPidPtr[0] == $gamePidPtr[0];

							// Right Ctrl toggle (VK_RCONTROL = 0xA3) - only when game is focused
							if (!isset($overlayVisible)) {
								$overlayVisible = true;
							}
							if (!isset($lastRCtrlState)) {
								$lastRCtrlState = false;
							}
							$rCtrlDown =
								($u32->GetAsyncKeyState(0xa3) & 0x8000) !== 0;
							if (
								$rCtrlDown &&
								!$lastRCtrlState &&
								$gameIsForeground
							) {
								$overlayVisible = !$overlayVisible;
								$threadLog(
									"Overlay toggled: " .
										($overlayVisible
											? "Visible"
											: "Hidden"),
								);
							}
							$lastRCtrlState = $rCtrlDown;

							$shouldShow = $overlayVisible && $gameIsForeground;
							if (!isset($wasShown)) { $wasShown = false; }

							if ($shouldShow) {
								if (!$wasShown) {
									$u32->ShowWindow($ovHwnd, 8); // SW_SHOWNA
									$wasShown = true;
									$needsPosUpdate = true; // Refresh on reveal
								}
								// Update z-order and position ONLY if game window handle changed, visibility changed, or moved
								static $lastTopGameHwnd = null;
								if ($lastTopGameHwnd !== $gameHwnd || !empty($needsPosUpdate)) {
									$u32->SetWindowPos(
										$ovHwnd,
										$u32->cast("HWND", -1), // HWND_TOPMOST
										$pptDst->x,
										$pptDst->y,
										$psize->cx,
										$psize->cy,
										0x0010 | 0x0040, // SWP_NOACTIVATE | SWP_SHOWWINDOW
									);
									$lastTopGameHwnd = $gameHwnd;
									$needsPosUpdate = false;
								}
							} else {
								if ($wasShown) {
									$u32->ShowWindow($ovHwnd, 0); // SW_HIDE
									$wasShown = false;
								}
							}
							// Responsive sleep: check for shutdown signal quickly
							if ($running) {
								$k32->Sleep(4);
							}
						}
					}
				} catch (\Throwable $e) {
					$threadLog("Main Loop Error: " . $e->getMessage());
				}
				try {
					// Cleanup Win32 Resources Explicitly
					if ($ovHwnd) {
						$u32->DestroyWindow($ovHwnd);
					}
					if ($className) {
						$u32->UnregisterClassA(
							$u32->cast("LPCSTR", $className),
							$wcex->hInstance,
						);
					}
					if ($memBmp) {
						$g32->DeleteObject($memBmp);
					}
					if ($memHdc) {
						$g32->DeleteDC($memHdc);
					}
					if ($oFont) {
						$g32->DeleteObject($oFont);
					}
					if ($magentaBrush) {
						$g32->DeleteObject($magentaBrush);
					}
					if ($darkBrush) {
						$g32->DeleteObject($darkBrush);
					}
					if ($pdhQuery) {
						$pdh->PdhCloseQuery($pdhQuery);
					}
					if ($glCtx) {
						$opengl->wglMakeCurrent(null, null);
						$opengl->wglDeleteContext($glCtx);
					}
					if ($glHdc) {
						$u32->ReleaseDC($glHwnd, $glHdc);
					}
					if ($glHwnd) {
						$u32->DestroyWindow($glHwnd);
					}
					$threadLog("GDI, PDH, and OpenGL Resources Released.");
				} catch (\Throwable $e) {
					$threadLog("Cleanup Error: " . $e->getMessage());
				}

				$threadLog("Overlay Thread Shutting Down.");
				// Signal main thread that we are fully cleaned up
				try {
					$channel->send("done");
					$threadLog("'done' signal sent to channel.");
				} catch (\Throwable $e) {
					$threadLog("Failed to send 'done': " . $e->getMessage());
				}
			},
			[$this->overlayChannel, $settings, $gamePid, $logPath],
		);
	}

	private function stopOverlayThread($wait = false)
	{
		if ($this->overlayChannel && !$this->isStoppingOverlay) {
			try {
				$this->log("Sending shutdown signal to overlay...");
				$this->overlayChannel->send("shutdown");
				$this->isStoppingOverlay = true;
			} catch (\Throwable $e) {
				$this->log("Error sending shutdown: " . $e->getMessage());
			}
		}

		if ($wait && $this->overlayFuture) {
			$start = microtime(true);
			while (
				!$this->overlayFuture->done() &&
				microtime(true) - $start < 1.0
			) {
				usleep(10000);
			}
			$this->overlayThread = null;
			$this->overlayChannel = null;
			$this->overlayFuture = null;
			$this->isStoppingOverlay = false;
		}
	}

	private function terminateGame()
	{
		if ($this->gamePid) {
			$this->log("Terminating Minecraft process tree (PID: {$this->gamePid})...");
			shell_exec("taskkill /F /T /PID {$this->gamePid}");
			$this->gamePid = null;
			$this->assetMessage = "GAME TERMINATED";
			$this->updateDiscordPresence();
		}
	}

	private function initLogs()
	{
		$logDir = __DIR__ . DIRECTORY_SEPARATOR . self::LOG_DIR;
		if (!is_dir($logDir)) {
			mkdir($logDir, 0777, true);
		}

		$latest = __DIR__ . DIRECTORY_SEPARATOR . self::LATEST_LOG;
		if (file_exists($latest)) {
			try {
				$time = filemtime($latest);
				$archiveName = "log-" . date("Y-m-d_H-i-s", $time) . ".log.gz";
				$content = file_get_contents($latest);
				if ($content) {
					$compressed = gzencode($content, 9);
					file_put_contents(
						$logDir . DIRECTORY_SEPARATOR . $archiveName,
						$compressed,
					);
					unlink($latest);
				}
			} catch (\Throwable $e) {
			}
		}
	}

	private function log($message, $level = "INFO")
	{
		$level = strtoupper($level);
		$messageformated = sprintf(
			"[%s] [%s] %s\n",
			date("Y-m-d H:i:s"),
			$level,
			$message,
		);

		// Optional: Console color coding for Windows if supported, or just plain echo
		echo $messageformated;

		$logPath = __DIR__ . DIRECTORY_SEPARATOR . self::LATEST_LOG;
		@file_put_contents($logPath, $messageformated, FILE_APPEND);
	}

	private function logNetwork(
		$url,
		$method,
		$data = null,
		$response = null,
		$code = null,
	) {
		$sanitizedData = $data;
		if ($data && (is_array($data) || is_object($data))) {
			$sanitizedData = json_encode($data);
		}
		
		if ($sanitizedData && is_string($sanitizedData)) {
			// Redact sensitive info from logs
			$sanitizedData = preg_replace(
				'/"(password|accessToken|clientToken|refreshToken|totpCode)":"[^"]+"/',
				'"$1":"[REDACTED]"',
				$sanitizedData,
			);
		}

		$logText = "Network: $method $url";
		if ($sanitizedData) {
			$logText .= " | Payload: $sanitizedData";
		}
		if ($code) {
			$logText .= " | Status: $code";
		}
		if ($response) {
			$sanitizedRes = $response;
			if (is_array($response) || is_object($response)) {
				$sanitizedRes = json_encode($response);
			}
			$sanitizedRes = preg_replace(
				'/"(accessToken|clientToken|refreshToken|id_token)":"[^"]+"/',
				'"$1":"[REDACTED]"',
				$sanitizedRes,
			);
			$logText .= " | Response: $sanitizedRes";
		}
	}

	private function updateOverlay()
	{
		$anyEnabled =
			$this->settings["overlay_cpu"] ||
			$this->settings["overlay_gpu"] ||
			$this->settings["overlay_ram"] ||
			$this->settings["overlay_vram"];
		
		$isGameRunning = ($this->assetMessage === "GAME RUNNING");

		if (!$anyEnabled || !$isGameRunning) {
			if (!$this->isStoppingOverlay) {
				$this->stopOverlayThread();
			}
			return;
		}

		// Re-ensure thread is running if it should be
		if (!$this->overlayThread && !$this->isStoppingOverlay) {
			$mcStatus = $this->checkGameWindow();
			if ($mcStatus['found']) {
				$this->startOverlayThread($mcStatus['pid']);
			}
		}
	}

	private function startOAuthListener()
	{
		$this->stopOAuthListener();
		$this->oauthServer = @stream_socket_server("tcp://localhost:" . $this->oauthPort, $errno, $errstr);
		if ($this->oauthServer) {
			stream_set_blocking($this->oauthServer, false);
			$this->log("OAuth loopback listener started on port " . $this->oauthPort);
		} else {
			$this->log("Failed to start OAuth listener: $errstr ($errno)", "ERROR");
		}
	}

	private function stopOAuthListener()
	{
		if ($this->oauthServer) {
			@fclose($this->oauthServer);
			$this->oauthServer = null;
			$this->log("OAuth loopback listener stopped.");
		}
	}

	private function pollOAuthServer()
	{
		if (!$this->oauthServer) return;

		$conn = @stream_socket_accept($this->oauthServer, 0);
		if ($conn) {
			$request = fread($conn, 4096);
			if (preg_match('/GET \/callback\?([^ ]+) HTTP/', $request, $matches)) {
				parse_str($matches[1], $params);
				if (isset($params['code'])) {
					$code = $params['code'];
					$state = $params['state'] ?? "";
					
					$this->log("Received OAuth code via loopback: " . substr($code, 0, 5) . "...");
					
					// Send success response to browser
					$response = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n";
					$response .= "<html><body style='font-family:sans-serif;text-align:center;padding-top:50px;background:#111;color:#eee;'>";
					$response .= "<div style='background:#222;display:inline-block;padding:30px;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.5);border:1px solid #333;'>";
					$response .= "<h2 style='color:#00e5ff;margin-top:0;'>Authentication Success!</h2><p>You can close this window now and return to FoxyClient.</p>";
					$response .= "</div></body></html>";
					fwrite($conn, $response);
					fclose($conn);

					// Process the code
					$this->loginInputCode = $code;
					if ($this->loginType === self::ACC_ELYBY) {
						$this->completeElybyLogin($code);
					} elseif ($this->loginType === self::ACC_FOXY) {
						$this->completeFoxyLogin($code);
					}
					$this->stopOAuthListener();
					return;
				}
			}
			fclose($conn);
		}
	}

	private function authenticateElybyClassic($username, $password)
	{
		$this->log("Attempting Ely.by Classic login for: $username");
		$this->msError = "";

		$res = $this->httpPost("https://ely.by/api/authlib/authenticate", json_encode([
			"username" => $username,
			"password" => $password,
			"agent" => ["name" => "Minecraft", "version" => 1]
		]), ["Content-Type: application/json"]);

		$data = json_decode($res, true);
		if (isset($data["accessToken"])) {
			$uuid = $data["selectedProfile"]["id"];
			$name = $data["selectedProfile"]["name"];

			$this->addOrUpdateAccount($uuid, $name, $data["accessToken"], "", time() + 3600 * 24, self::ACC_ELYBY);
			$this->switchPage(self::PAGE_HOME);
			$this->updateDiscordPresence();
			$this->log("Ely.by Classic login successful: $name ($uuid)");
			return true;
		}

		$this->msError = $data["errorMessage"] ?? "Classic authentication failed.";
		$this->log("Ely.by Classic login failed: " . $res, "ERROR");
		return false;
	}

	private function authenticateFoxyClassic($username, $password)
	{
		$this->log("Attempting FoxyClient Classic login for: $username");
		$this->msError = "";

		$res = $this->httpPost("https://foxyclient.qzz.io/api/authenticate", json_encode([
			"username" => $username,
			"password" => $password,
			"agent" => ["name" => "Minecraft", "version" => 1]
		]), ["Content-Type: application/json"]);

		$data = json_decode($res, true);
		if (isset($data["accessToken"])) {
			$uuid = $data["selectedProfile"]["id"];
			$name = $data["selectedProfile"]["name"];

			$this->addOrUpdateAccount($uuid, $name, $data["accessToken"], "", time() + 3600 * 24, self::ACC_FOXY);
			$this->switchPage(self::PAGE_HOME);
			$this->updateDiscordPresence();
			$this->log("FoxyClient Classic login successful: $name ($uuid)");
			return true;
		}

		$this->msError = $data["errorMessage"] ?? "Classic authentication failed.";
		$this->log("FoxyClient Classic login failed: " . $res, "ERROR");
		return false;
	}
	private function httpPost($url, $data, $headers = [])
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_USERAGENT, "FoxyClient/" . self::VERSION);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt(
			$ch,
			CURLOPT_POSTFIELDS,
			is_array($data) ? http_build_query($data) : $data,
		);
		if (!empty($headers)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}
		$cacert = __DIR__ . DIRECTORY_SEPARATOR . self::CACERT;
		if (file_exists($cacert)) {
			curl_setopt($ch, CURLOPT_CAINFO, $cacert);
		}
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

		$res = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$this->logNetwork($url, "POST", $data, $res, $httpCode);
		return $res;
	}

	private function httpGet($url, $headers = [])
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_USERAGENT, "FoxyClient/" . self::VERSION);
		if (!empty($headers)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}
		$cacert = __DIR__ . DIRECTORY_SEPARATOR . self::CACERT;
		if (file_exists($cacert)) {
			curl_setopt($ch, CURLOPT_CAINFO, $cacert);
		}
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

		$res = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$this->logNetwork($url, "GET", null, $res, $httpCode);
		return $res;
	}

	private function addOrUpdateAccount($uuid, $name, $accessToken, $refreshToken, $expiresAt, $type)
	{
		foreach ($this->accounts as $k => $acc) {
			if ($acc["Type"] === $type && strcasecmp($acc["Username"], $name) === 0 && $k !== $uuid) {
				unset($this->accounts[$k]);
			}
		}
		$this->accounts[$uuid] = [
			"Username" => $name,
			"AccessToken" => $accessToken,
			"RefreshToken" => $refreshToken,
			"ExpiresAt" => $expiresAt,
			"Type" => $type,
		];
		$this->activeAccount = $uuid;
		$this->accountName = $name;
		$this->isLoggedIn = true;
		$this->saveAccounts();
	}

	private function initMicrosoftLogin()
	{
		// Only initialize login state variables, don't perform network requests here
		$this->msDeviceCode = "";
		$this->msUserCode = "";
		$this->msVerificationUri = "";
		$this->msPollingInterval = 5;
		$this->loginStep = 0;
		return true;
	}

	private function startMicrosoftOAuth()
	{
		$clientId = "00000000402b5328"; // Minecraft Launcher Client ID
		$scope = "service::user.auth.xboxlive.com::MBI_SSL";

		$res = $this->httpPost("https://login.live.com/oauth20_connect.srf", [
			"client_id" => $clientId,
			"response_type" => "device_code",
			"scope" => $scope,
		]);

		$data = json_decode($res, true);
		if (isset($data["device_code"])) {
			$this->msDeviceCode = $data["device_code"];
			$this->msUserCode = $data["user_code"];
			$this->msVerificationUri = $data["verification_uri"];
			$this->msPollingInterval = $data["interval"] ?? 5;
			$this->loginStep = 1;

			// Automatically open the browser for the user
			if ($this->msVerificationUri) {
				pclose(popen("start \"\" \"{$this->msVerificationUri}\"", "r"));
			}
			
			return true;
		}
		$this->msError =
			$data["error_description"] ?? "Failed to get device code";
		return false;
	}

	private function pollMicrosoftStatus()
	{
		if (
			!$this->msDeviceCode ||
			microtime(true) - $this->msLastPollTime < $this->msPollingInterval
		) {
			return;
		}
		$this->msLastPollTime = microtime(true);

		$clientId = "00000000402b5328";
		$res = $this->httpPost("https://login.live.com/oauth20_token.srf", [
			"client_id" => $clientId,
			"grant_type" => "urn:ietf:params:oauth:grant-type:device_code",
			"device_code" => $this->msDeviceCode,
		]);

		$data = json_decode($res, true);
		if (isset($data["access_token"])) {
			$this->completeMicrosoftLogin(
				$data["access_token"],
				$data["refresh_token"] ?? "",
			);
		} elseif (isset($data["error"])) {
			if ($data["error"] === "authorization_pending") {
				// Keep polling
			} else {
				$this->msError = $data["error_description"] ?? $data["error"];
				$this->loginStep = 0;
				$this->log(
					"Microsoft login polling failed: " .
						($data["error_description"] ?? $data["error"]),
				);
			}
		}
	}

	private function completeMicrosoftLogin($accessToken, $refreshToken)
	{
		$this->log("Attempting Microsoft login...");
		// 1. Authenticate with XBL
		$xblRes = $this->httpPost(
			"https://user.auth.xboxlive.com/user/authenticate",
			json_encode([
				"Properties" => [
					"AuthMethod" => "RPS",
					"SiteName" => "user.auth.xboxlive.com",
					"RpsTicket" => "d=" . $accessToken,
				],
				"RelyingParty" => "http://auth.xboxlive.com",
				"TokenType" => "JWT",
			], JSON_UNESCAPED_SLASHES),
			["Content-Type: application/json", "Accept: application/json"],
		);

		$xblData = json_decode($xblRes, true);
		if (!isset($xblData["Token"])) {
			$this->log("Microsoft login failed: XBL authentication failed. Response: " . $xblRes, "ERROR");
			$this->msError = "XBL authentication failed.";
			$this->loginStep = 0;
			return;
		}
		$xblToken = $xblData["Token"];
		$uhs = $xblData["DisplayClaims"]["xui"][0]["uhs"];

		// 2. Authenticate with XSTS
		$xstsRes = $this->httpPost(
			"https://xsts.auth.xboxlive.com/xsts/authorize",
			json_encode([
				"Properties" => [
					"SandboxId" => "RETAIL",
					"UserTokens" => [$xblToken],
				],
				"RelyingParty" => "rp://api.minecraftservices.com/",
				"TokenType" => "JWT",
			], JSON_UNESCAPED_SLASHES),
			["Content-Type: application/json", "Accept: application/json"],
		);
		$xstsData = json_decode($xstsRes, true);
		if (!isset($xstsData["Token"])) {
			$this->log("Microsoft login failed: XSTS authentication failed. Response: " . $xstsRes, "ERROR");
			$this->msError = "XSTS authentication failed.";
			$this->loginStep = 0;
			return;
		}
		$xstsToken = $xstsData["Token"];

		// 3. Authenticate with Minecraft
		$mcRes = $this->httpPost(
			"https://api.minecraftservices.com/authentication/login_with_xbox",
			json_encode([
				"identityToken" => "XBL3.0 x=$uhs;$xstsToken",
			], JSON_UNESCAPED_SLASHES),
			["Content-Type: application/json", "Accept: application/json"],
		);

		$mcData = json_decode($mcRes, true);
		if (!isset($mcData["access_token"])) {
			$this->log(
				"Microsoft login failed: Minecraft authentication failed.",
			);
			return;
		}
		$mcToken = $mcData["access_token"];

		// 4. Get Profile
		$profileRes = $this->httpGet(
			"https://api.minecraftservices.com/minecraft/profile",
			["Authorization: Bearer $mcToken"],
		);

		$profile = json_decode($profileRes, true);
		if (isset($profile["id"])) {
			$uuid = $profile["id"];
			$name = $profile["name"];

			$this->addOrUpdateAccount($uuid, $name, $mcToken, $refreshToken, time() + ($mcData["expires_in"] ?? 3600), self::ACC_MICROSOFT);
			$this->switchPage(self::PAGE_HOME);
			$this->loginStep = 0;
			$this->updateDiscordPresence();
			$this->log("Microsoft login successful for user: $name ($uuid)");
		} else {
			$this->log(
				"Microsoft login failed: Could not retrieve Minecraft profile.",
			);
		}
	}

	private function completeElybyLogin($code)
	{
		$this->log("Completing Ely.by OAuth login...");
		$this->msError = "";

		$params = [
			"grant_type" => "authorization_code",
			"code" => $code,
			"client_id" => $this->elyClientId,
			"client_secret" => $this->elyClientSecret,
			"redirect_uri" => "http://localhost:" . $this->oauthPort . "/callback",
		];

		$res = $this->httpPost("https://account.ely.by/api/oauth2/v1/token", $params);

		$data = json_decode($res, true);
		if (isset($data["access_token"])) {
			// Get profile info
			$profileRes = $this->httpGet("https://account.ely.by/api/account/v1/info", [
				"Authorization: Bearer " . $data["access_token"]
			]);
			$profile = json_decode($profileRes, true);
			
			if (isset($profile["uuid"]) && isset($profile["username"])) {
				$uuid = $profile["uuid"];
				$name = $profile["username"];
				$this->addOrUpdateAccount($uuid, $name, $data["access_token"], $data["refresh_token"] ?? "", time() + ($data["expires_in"] ?? 3600), self::ACC_ELYBY);
				$this->switchPage(self::PAGE_HOME);
				$this->loginStep = 0;
				$this->loginInputCode = "";
				$this->updateDiscordPresence();
				$this->log("Ely.by OAuth login successful: $name ($uuid)");
				return true;
			}
		}

		$this->msError = $data["error_description"] ?? ($data["error_message"] ?? "Ely.by OAuth exchange failed.");
		$this->log("Ely.by OAuth failed: " . $res, "ERROR");
		return false;
	}

	private function completeFoxyLogin($code)
	{
		$this->log("Completing FoxyClient OAuth login...");
		$this->msError = "";

		$res = $this->httpPost("https://foxyclient.qzz.io/oauth/token/", [
			"grant_type" => "authorization_code",
			"code" => $code,
			"client_id" => $this->foxyClientId,
			"client_secret" => $this->foxyClientSecret,
			"redirect_uri" => $this->foxyRedirectUri,
		]);

		$data = json_decode($res, true);
		if (isset($data["access_token"])) {
			// Get profile info
			$profileRes = $this->httpGet("https://foxyclient.qzz.io/api/oauth2/v1/userinfo/", [
				"Authorization: Bearer " . $data["access_token"]
			]);
			$profile = json_decode($profileRes, true);
			
			if (isset($profile["uuid"]) && isset($profile["username"])) {
				$uuid = $profile["uuid"];
				$name = $profile["username"];
				$this->addOrUpdateAccount($uuid, $name, $data["access_token"], $data["refresh_token"] ?? "", time() + ($data["expires_in"] ?? 3600), self::ACC_FOXY);
				$this->switchPage(self::PAGE_HOME);
				$this->loginStep = 0;
				$this->loginInputCode = "";
				$this->updateDiscordPresence();
				$this->log("FoxyClient OAuth login successful: $name ($uuid)");
				return true;
			}
		}

		$this->msError = $data["error_description"] ?? ($data["error_message"] ?? "FoxyClient OAuth exchange failed.");
		$this->log("FoxyClient OAuth failed: " . $res, "ERROR");
		return false;
	}

	private function initGL()
	{
		$gl = $this->opengl32;
		$gl->glViewport(0, 0, $this->width, $this->height);
		$gl->glMatrixMode(0x1701); // GL_PROJECTION
		$gl->glLoadIdentity();
		$gl->glOrtho(0, $this->width, $this->height, 0, -1, 1);
		$gl->glMatrixMode(0x1700); // GL_MODELVIEW
		$gl->glLoadIdentity();
		$gl->glEnable(0x0be2); // GL_BLEND
		$gl->glBlendFunc(0x0302, 0x0303); // SRC_ALPHA, ONE_MINUS_SRC_ALPHA
		$gl->glEnable(0x0c11); // GL_SCISSOR_TEST
	}

	private function ensureAuthlibInjector()
	{
		$path =
			__DIR__ .
			DIRECTORY_SEPARATOR .
			self::CACHE_DIR .
			DIRECTORY_SEPARATOR .
			"authlib-injector.jar";
		// Check if exists and is a valid JAR size (usually ~300KB+)
		if (file_exists($path) && filesize($path) > 300000) {
			$this->log("Authlib injector already present and valid.");
			return true;
		}

		$this->log("Downloading Authlib injector...");
		$url =
			"https://github.com/yushijinhun/authlib-injector/releases/download/v1.2.7/authlib-injector-1.2.7.jar";
		// GitHub requires a User-Agent
		$data = $this->httpGet($url, [
			"User-Agent: FoxyClient/" . self::VERSION,
		]);
		if (!$data || strlen($data) < 300000) {
			$this->log(
				"Failed to download Authlib injector or file too small.",
				"ERROR",
			);
			return false;
		}

		file_put_contents($path, $data);
		$this->log("Authlib injector downloaded and saved.");
		return true;
	}

	public function run()
	{
		try {
			$msg = $this->user32->new("MSG");
			while ($this->running) {
				// --- GPU Resource Saving: Detect state ---
				$isFocused = ($this->user32->GetForegroundWindow() == $this->hwnd);
				$isMinimized = $this->user32->IsIconic($this->hwnd);
				$isHovered = $this->mouseX >= 0 && $this->mouseX <= $this->width && $this->mouseY >= 0 && $this->mouseY <= $this->height;
				$isVisible = $this->user32->IsWindowVisible($this->hwnd);
				$isGameRunning = $this->gameProcess !== null;

				// Throttling decision: Cap FPS if unfocused/not hovered/minimized OR if game is running
				$shouldThrottle = (!$isFocused && !$isHovered) || $isMinimized || ($isGameRunning && $isVisible);

				// --- No-Rendering: Skip everything if game is running and launcher is hidden ---
				if ($isGameRunning && !$isVisible) {
					// Sleep longer (200ms = 5Hz) for extremely low CPU/GPU usage
					usleep(200000); 
					$this->pollProcess();
					$this->pollOAuthServer();
					continue;
				}

				// --- Idle detection: block on messages when nothing to do (0% CPU) ---
				if ($this->isIdle) {
					$timeout = $shouldThrottle ? 100 : 0;
					$this->user32->WaitMessage();
					$this->needsRedraw = true;
					$this->isIdle = false;
				}

				while (
					$this->user32->PeekMessageA(FFI::addr($msg), null, 0, 0, 1)
				) {
					$this->user32->TranslateMessage(FFI::addr($msg));
					$this->user32->DispatchMessageA(FFI::addr($msg));
				}

				// --- Fast polling: runs every iteration (~2000x/sec) ---
				$this->pollProcess();
				$this->pollOAuthServer();

				// If no redraw needed AND no background work, go idle
				if (!$this->needsRedraw && !$this->hasActiveBackgroundTasks()) {
					$this->isIdle = true;
					continue;
				}

				// --- Throttling: Skip render frames if unfocused to save GPU ---
				if ($shouldThrottle) {
					// Cap to ~15 FPS unfocused, or ~5 FPS if game is running (and visible)
					$throttleSleep = ($isGameRunning && $isVisible) ? 200000 : 66000;
					usleep($throttleSleep);
				}

				// --- Rendering: matches screen refresh rate via VSync ---
				$now = microtime(true);
				$lastRenderTime = $now;

				// Smooth scroll interpolation
				$diff = $this->scrollTarget - $this->scrollOffset;
				if (abs($diff) > 0.5) {
					$this->scrollOffset += $diff * $this->scrollSpeed;
				} else {
					$this->scrollOffset = $this->scrollTarget;
				}

				$vDiff = $this->vScrollTarget - $this->vScrollOffset;
				if (abs($vDiff) > 0.5) {
					$this->vScrollOffset += $vDiff * $this->scrollSpeed;
				} else {
					$this->vScrollOffset = $this->vScrollTarget;
				}

				$pDiff = $this->propScrollTarget - $this->propScrollOffset;
				if (abs($pDiff) > 0.5) {
					$this->propScrollOffset += $pDiff * $this->scrollSpeed;
				} else {
					$this->propScrollOffset = $this->propScrollTarget;
				}

				$aDiff = $this->accScrollTarget - $this->accScrollOffset;
				if (abs($aDiff) > 0.5) $this->accScrollOffset += $aDiff * $this->scrollSpeed;
				else $this->accScrollOffset = $this->accScrollTarget;

				$fkDiff = $this->foxyKeybindScrollTarget - $this->foxyKeybindScrollOffset;
				if (abs($fkDiff) > 0.5) $this->foxyKeybindScrollOffset += $fkDiff * $this->scrollSpeed;
				else $this->foxyKeybindScrollOffset = $this->foxyKeybindScrollTarget;

				$fmDiff = $this->foxyMacroScrollTarget - $this->foxyMacroScrollOffset;
				if (abs($fmDiff) > 0.5) $this->foxyMacroScrollOffset += $fmDiff * $this->scrollSpeed;
				else $this->foxyMacroScrollOffset = $this->foxyMacroScrollTarget;

				$fcDiff = $this->foxyConfigScrollTarget - $this->foxyConfigScrollOffset;
				if (abs($fcDiff) > 0.5) $this->foxyConfigScrollOffset += $fcDiff * $this->scrollSpeed;
				else $this->foxyConfigScrollOffset = $this->foxyConfigScrollTarget;

				$hDiff =
					$this->homeVerScrollTarget - $this->homeVerScrollOffset;
				if (abs($hDiff) > 0.5) {
					$this->homeVerScrollOffset += $hDiff * $this->scrollSpeed;
				} else {
					$this->homeVerScrollOffset = $this->homeVerScrollTarget;
				}

				$fDiff = $this->modsFilterScrollTarget - $this->modsFilterScrollOffset;
				if (abs($fDiff) > 0.5) {
					$this->modsFilterScrollOffset += $fDiff * $this->scrollSpeed;
				} else {
					$this->modsFilterScrollOffset = $this->modsFilterScrollTarget;
				}

				$sDiff = $this->sidebarTargetY - $this->sidebarIndicatorY;
				if (abs($sDiff) > 0.1) {
					$this->sidebarIndicatorY += $sDiff * 0.2;
				} else {
					$this->sidebarIndicatorY = $this->sidebarTargetY;
				}

				$hDiff = $this->sidebarHoverTargetY - $this->sidebarHoverY;
				if (abs($hDiff) > 0.1) {
					$this->sidebarHoverY += $hDiff * 0.25;
				} else {
					$this->sidebarHoverY = $this->sidebarHoverTargetY;
				}

				if ($this->sidebarHover !== -1 && $this->sidebarHover !== 99) {
					$this->sidebarHoverAlpha = min(
						1.0,
						$this->sidebarHoverAlpha + 0.1,
					);
				} else {
					$this->sidebarHoverAlpha = max(
						0.0,
						$this->sidebarHoverAlpha - 0.1,
					);
				}

				// Window launch animation interpolation
				$elapsed = $now - $this->appLaunchTime;
				if ($elapsed < 3.0) {
					$this->windowAnim = ($elapsed / 3.0) * 0.4;
				} else {
					if ($this->windowAnim < 1.0) {
						$this->windowAnim += (1.0 - $this->windowAnim) * 0.08;
						if ($this->windowAnim >= 0.998) {
							$this->windowAnim = 1.0;
						}
					}
				}

				$this->pageAnim = min(1.0, $this->pageAnim + 0.1);
				$this->modrinthAnim = min(1.0, $this->modrinthAnim + 0.16); // Snappier Results Fade-In

				if ($this->homeAccDropdownOpen) {
					$this->homeAccDropdownAnim = min(
						1.0,
						$this->homeAccDropdownAnim + 0.15,
					);
				} else {
					$this->homeAccDropdownAnim = max(
						0.0,
						$this->homeAccDropdownAnim - 0.15,
					);
				}

				if ($this->homeVerDropdownOpen) {
					$this->homeVerDropdownAnim = min(
						1.0,
						$this->homeVerDropdownAnim + 0.1,
					);
				} else {
					$this->homeVerDropdownAnim = max(
						0.0,
						$this->homeVerDropdownAnim - 0.1,
					);
				}

				if ($this->javaModalDropdownOpen) {
					$this->javaModalDropdownAnim = min(
						1.0,
						$this->javaModalDropdownAnim + 0.15,
					);
				} else {
					$this->javaModalDropdownAnim = max(
						0.0,
						$this->javaModalDropdownAnim - 0.15,
					);
				}

				if ($this->modsVerDropdownOpen) {
					$this->modsVerDropdownAnim = min(
						1.0,
						$this->modsVerDropdownAnim + 0.15,
					);
				} else {
					$this->modsVerDropdownAnim = max(
						0.0,
						$this->modsVerDropdownAnim - 0.15,
					);
				}

				if ($this->modsFilterDropdown !== "") {
					$this->modsFilterDropdownAnim = min(
						1.0,
						$this->modsFilterDropdownAnim + 0.15,
					);
				} else {
					$this->modsFilterDropdownAnim = max(
						0.0,
						$this->modsFilterDropdownAnim - 0.15,
					);
				}

				$this->buttonPulse += 0.03;
				if ($this->buttonPulse > 6.283) {
					$this->buttonPulse -= 6.283;
				}

				$this->lastTime = $now;

				// Interpolate Icon Alphas
				foreach ($this->modIconAlpha as $id => $alpha) {
					if ($alpha < 1.0) {
						$this->modIconAlpha[$id] = min(1.0, $alpha + 0.05);
						$this->needsRedraw = true;
					}
				}

				$this->computeHoverStates();

				if (
					$this->currentPage === self::PAGE_LOGIN &&
					$this->loginType === self::ACC_MICROSOFT &&
					$this->loginStep === 1
				) {
					$this->pollMicrosoftStatus();
				}

				// pollProcess moved to before idle check
				$this->render();
				$this->gdi32->SwapBuffers($this->hdc);

				// Fallback VSync for windowed mode: DwmFlush blocks until next V-Blank
				if ($this->dwmapi) {
					try {
						$this->dwmapi->DwmFlush();
					} catch (\Throwable $e) {
					}
				}

				// Lifecycle / Redraw Logic
				$animating = false;
				$animating =
					$animating ||
					abs($this->scrollTarget - $this->scrollOffset) > 0.5;
				$animating =
					$animating ||
					abs($this->vScrollTarget - $this->vScrollOffset) > 0.5;
				$animating =
					$animating ||
					abs($this->propScrollTarget - $this->propScrollOffset) >
						0.5;
				$animating =
					$animating ||
					abs(
						$this->homeVerScrollTarget - $this->homeVerScrollOffset,
					) > 0.5;
				$animating =
					$animating ||
					abs($this->modsFilterScrollTarget - $this->modsFilterScrollOffset) > 0.5;
				$animating =
					$animating ||
					abs($this->accScrollTarget - $this->accScrollOffset) > 0.5;
				$animating =
					$animating ||
					abs($this->foxyKeybindScrollTarget - $this->foxyKeybindScrollOffset) > 0.5;
				$animating =
					$animating ||
					abs($this->foxyMacroScrollTarget - $this->foxyMacroScrollOffset) > 0.5;
				$animating =
					$animating ||
					abs($this->foxyConfigScrollTarget - $this->foxyConfigScrollOffset) > 0.5;
				$animating =
					$animating ||
					abs($this->sidebarTargetY - $this->sidebarIndicatorY) > 0.5;
				$animating = $animating || $this->pageAnim < 1.0;
				$animating = $animating || $this->modrinthAnim < 1.0;
				$animating =
					$animating ||
					($this->homeAccDropdownOpen &&
						$this->homeAccDropdownAnim < 1.0);
				$animating =
					$animating ||
					(!$this->homeAccDropdownOpen &&
						$this->homeAccDropdownAnim > 0.01);
				$animating =
					$animating ||
					($this->homeVerDropdownOpen &&
						$this->homeVerDropdownAnim < 1.0);
				$animating =
					$animating ||
					(!$this->homeVerDropdownOpen &&
						$this->homeVerDropdownAnim > 0.01);
				$animating =
					$animating ||
					($this->javaModalDropdownOpen &&
						$this->javaModalDropdownAnim < 1.0);
				$animating =
					$animating ||
					(!$this->javaModalDropdownOpen &&
						$this->javaModalDropdownAnim > 0.01);
				$animating =
					$animating ||
					($this->modsVerDropdownOpen &&
						$this->modsVerDropdownAnim < 1.0);
				$animating =
					$animating ||
					(!$this->modsVerDropdownOpen &&
						$this->modsVerDropdownAnim > 0.01);
				$animating =
					$animating ||
					($this->modsFilterDropdown !== "" &&
						$this->modsFilterDropdownAnim < 1.0);
				$animating =
					$animating ||
					($this->modsFilterDropdown === "" &&
						$this->modsFilterDropdownAnim > 0.01);
				$animating =
					$animating ||
					$this->isLaunching ||
					$this->isDownloadingAssets;
				$animating =
					$animating ||
					$this->gameProcess !== null ||
					$this->isStoppingOverlay;
				$animating =
					$animating ||
					$this->isSearchingModrinth ||
					$this->isCheckingCompat;
				$animating = $animating || $this->windowAnim < 1.0;

				if ($animating) {
					$this->needsRedraw = true;
				} else {
					$this->needsRedraw = false;
				}
			}
		} catch (\Throwable $e) {
			$this->log("FATAL ERROR: " . $e->getMessage(), "ERROR");
			$this->log("Stack Trace:\n" . $e->getTraceAsString(), "ERROR");
			if ($this->user32) {
				$this->user32->MessageBoxA(
					$this->hwnd,
					"A fatal error occurred. Check logs for details: " .
						$e->getMessage(),
					"FoxyClient Fatal Error",
					0x10,
				);
			}
		} finally {
			$this->log("FoxyClient Shutting Down...");
			$this->cleanup();
			exit(0);
		}
	}

	private function computeHoverStates()
	{
		$x = $this->mouseX;
		$y = $this->mouseY;

		// Title bar hovers
		$this->titleDragHover = false;
		$this->titleCloseHover = false;
		$this->titleMinHover = false;
		if ($y < self::TITLEBAR_H) {
			if ($x >= $this->width - 45) {
				$this->titleCloseHover = true;
			} elseif ($x >= $this->width - 90) {
				$this->titleMinHover = true;
			} else {
				$this->titleDragHover = true;
			}
			return;
		}

		if ($this->bgModalOpen) {
			$this->computeBgModalHover($x, $y);
			return;
		}

		if ($this->javaModalOpen) {
			$this->computeJavaModalHover($x, $y);
			return;
		}

		// Sidebar hovers
		$this->sidebarHover = -1;
		if ($x < self::SIDEBAR_W && $y >= self::TITLEBAR_H) {
			// Profile area hover
			if ($y >= $this->height - 74 && $y <= $this->height - 24) {
				$this->sidebarHover = 99;
				return;
			}

			$itemH = 50;
			$startY = 100 + self::TITLEBAR_H;
			foreach ($this->sidebarItems as $i => $item) {
				if ($y >= $startY && $y < $startY + $itemH) {
					$this->sidebarHover = $i;
					$this->sidebarHoverTargetY = $startY - self::TITLEBAR_H;
					break;
				}
				$startY += $itemH + 5;
			}
			return;
		}

		// Content area hover
		$cx = $x - self::SIDEBAR_W;
		$cy = $y - self::TITLEBAR_H;
		if ($this->currentPage === self::PAGE_HOME) {
			$this->computeHomePageHover($cx, $cy);
		} elseif ($this->currentPage === self::PAGE_FOXYCLIENT) {
			$this->computeFoxyClientSettingsHover($cx, $cy);
		} elseif ($this->currentPage === self::PAGE_MODS) {
			$this->computeModsPageHover($cx, $cy);
		} elseif ($this->currentPage === self::PAGE_LOGIN) {
			$this->computeLoginPageHover($cx, $cy);
		} elseif ($this->currentPage === self::PAGE_VERSIONS) {
			$this->computeVersionsPageHover($cx, $cy);
		} elseif ($this->currentPage === self::PAGE_ACCOUNTS) {
			$this->computeAccountsPageHover($cx, $cy);
		} elseif ($this->currentPage === self::PAGE_PROPERTIES) {
			$this->computePropertiesPageHover($cx, $cy);
		} else {
			$this->hoverModIndex = -1;
			$this->tabHover = -1;
			$this->buttonHover = false;
		}
	}

	private function computeAccountsPageHover($cx, $cy)
	{
		$this->accHoverIndex = "-1";
		$usableH = $this->height - self::TITLEBAR_H;
		$cw = $this->width - self::SIDEBAR_W;

		// Add button hover
		$addBtnW = 150;
		$addBtnH = 36;
		$addBtnX = $cw - self::PAD - $addBtnW;
		$addBtnY = 32;
		if ($cy >= $addBtnY && $cy <= $addBtnY + $addBtnH && $cx >= $addBtnX && $cx <= $addBtnX + $addBtnW) {
			$this->accHoverIndex = "add_btn";
			return;
		}

		$listTop = 110;
		$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
		$listH = $usableH - $footerH - $listTop;
		if ($cy >= $listTop && $cy <= $listTop + $listH && $cx >= self::PAD && $cx <= $cw - self::PAD) {
			$itemH = 64;
			$gap = 6;
			$localY = $cy - $listTop + $this->accScrollOffset;
			$idx = (int) floor($localY / ($itemH + $gap));
			$uuids = array_keys($this->accounts);
			
			if (isset($uuids[$idx])) {
				$uuid = $uuids[$idx];
				$itemLocalY = $localY - $idx * ($itemH + $gap);
				if ($itemLocalY <= $itemH) { 
					// Delete button bounds
					$delW = 32;
					$delH = 32;
					$delX = $cw - self::PAD - $delW - 16;
					$itemScreenY = $listTop + $idx * ($itemH + $gap) - $this->accScrollOffset;
					$delY = $itemScreenY + ($itemH - $delH) / 2;
					
					if ($cx >= $delX && $cx <= $delX + $delW && $cy >= $delY && $cy <= $delY + $delH) {
						$this->accHoverIndex = $uuid . "_del";
					} else {
						$this->accHoverIndex = $uuid;
					}
				}
			}
		}
	}

	private function computeLoginPageHover($cx, $cy)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$boxW = 300;
		$boxX = ($cw - $boxW) / 2;
		$this->loginButtonHover =
			$cx >= $boxX && $cx <= $boxX + $boxW && $cy >= 260 && $cy <= 300;
	}

	private function computeVersionsPageHover($cx, $cy)
	{
		$this->vHoverIndex = -1;
		$this->vTabHover = -1;
		$this->assetButtonHover = false;
		$this->assetUninstallHover = false;

		// Version tabs hover (Y=100, H=40)
		$y = 100;
		if ($cy >= $y && $cy < $y + self::TAB_H) {
			$tx = self::PAD;
			$cats = ["RELEASES", "SNAPSHOTS", "MODIFIED"];
			foreach ($cats as $i => $cat) {
				$tw = $this->getTextWidth($cat, 1000) + 30;
				if ($cx >= $tx && $cx < $tx + $tw) {
					$this->vTabHover = $i;
					return;
				}
				$tx += $tw;
			}
		}

		$usableH = $this->height - self::TITLEBAR_H;
		$listTop = $y + self::TAB_H;
		$bottomMargin = 150;
		$actionY = $usableH - $bottomMargin;

		// Action area hovers
		if ($cy >= $actionY + 45 && $cy <= $actionY + 45 + 36) {
			if ($cx >= self::PAD && $cx <= self::PAD + 200) {
				$this->assetButtonHover = true;
				return;
			}
			if ($cx >= self::PAD + 216 && $cx <= self::PAD + 216 + 150) {
				$this->assetUninstallHover = true;
				return;
			}
		}

		// Check version list hover (Unified: List starts at 140, item height 56+6=62)
		if ($cy >= $listTop && $cy < $actionY) {
			$filtered = $this->getFilteredVersions();
			$localY = $cy - $listTop + $this->vScrollOffset;
			$idx = (int) floor($localY / 62);
			if ($idx >= 0 && $idx < count($filtered)) {
				$this->vHoverIndex = $idx;
			}
		}

		$usableH = $this->height - self::TITLEBAR_H;
		$actionY = $usableH - 150;
		$this->assetButtonHover =
			$cx >= self::PAD &&
			$cx <= self::PAD + 200 &&
			$cy >= $actionY + 30 &&
			$cy <= $actionY + 70;
		$this->assetUninstallHover =
			$cx >= self::PAD + 210 &&
			$cx <= self::PAD + 360 &&
			$cy >= $actionY + 30 &&
			$cy <= $actionY + 70;
	}
	private function computeFoxyClientSettingsHover($cx, $cy)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$this->foxySettingsHoverIdx = -1;
		$this->subTabHoverIdx = -1;
		$this->foxyKeybindHoverIdx = -1;
		$this->foxyMacroHoverIdx = -1;
		$this->foxyConfigHoverIdx = -1;
		$this->foxyCosmeticsHoverIdx = -1;

		// Sub-tabs (6 tabs)
		if ($cy >= self::HEADER_H && $cy <= self::HEADER_H + self::TAB_H) {
			$tabX = self::PAD;
			foreach (["Modpack", "Keybinds", "Macros", "Config", "Cosmetics", "OSD"] as $i => $name) {
				$tw = $this->getTextWidth($name, 1000) + 32;
				if ($cx >= $tabX && $cx <= $tabX + $tw) {
					$this->subTabHoverIdx = 100 + $i;
					return;
				}
				$tabX += $tw + 8;
			}
		}

		switch ($this->foxySubTab) {
			case 0: // Modpack
				$y = self::HEADER_H + self::TAB_H;
				$h = $this->height - self::TITLEBAR_H - self::FOOTER_H - $y;
				if ($cx >= self::PAD && $cx <= $cw - self::PAD && $cy >= $y && $cy <= $y + $h) {
					$mods = $this->tabs[0]["mods"] ?? [];
					$itemY = $y + 10 - $this->scrollOffset;
					foreach ($mods as $i => $mod) {
						if ($cy >= $itemY && $cy < $itemY + self::CARD_H) {
							$this->hoverModIndex = $i;
							return;
						}
						$itemY += self::CARD_H + self::CARD_GAP;
					}
				}
				break;
			case 1: // Keybinds
				// Search bar hover
				$searchW = 250;
				$searchX = $cw - self::PAD - $searchW;
				if ($cx >= $searchX && $cx <= $searchX + $searchW && $cy >= self::HEADER_H + self::TAB_H + 1 && $cy <= self::HEADER_H + self::TAB_H + 33) {
					// We can add a generic hover flag if needed, but the search bar rendering handles its own focus state.
					return;
				}

				$listTop = self::HEADER_H + self::TAB_H + 68;
				$itemH = 48;
				$localY = $cy - $listTop + $this->foxyKeybindScrollOffset;
				$idx = (int) floor($localY / $itemH);

				$filteredKeys = [];
				foreach (array_keys($this->foxyKeybindData) as $k) {
					if ($this->foxyKeybindSearchQuery === "" || stripos($k, $this->foxyKeybindSearchQuery) !== false) {
						$filteredKeys[] = $k;
					}
				}

				if ($cy >= $listTop && $idx >= 0 && $idx < count($filteredKeys)) {
					$this->foxyKeybindHoverIdx = $idx;
				}
				break;
			case 2: // Macros
				$listTop = self::HEADER_H + self::TAB_H + 38;
				$keys = array_keys($this->foxyMacroData);
				$itemH = 44;
				$localY = $cy - $listTop + $this->foxyMacroScrollOffset;
				$idx = (int) floor($localY / $itemH);
				$usableH = $this->height - self::TITLEBAR_H;
				$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
				$addBtnY = $usableH - $footerH - 40;
				if ($cy >= $addBtnY && $cy <= $addBtnY + 36 && $cx >= self::PAD && $cx <= self::PAD + 150) {
					$this->foxyMacroHoverIdx = -2;
				} elseif ($cy >= $listTop && $idx >= 0 && $idx < count($keys)) {
					$this->foxyMacroHoverIdx = $idx;
				}
				break;
			case 3: // Config
				$listTop = self::HEADER_H + self::TAB_H + 10;
				$hiddenKeys = ["skinName", "capeName", "slimModel", "customMusicName", "customFontName", "customBackgroundName", "customSkinPath", "customFontPath", "customBackgroundPath", "customMusicPath"];
				$keys = array_values(array_filter(array_keys($this->foxyConfigData), function($k) use ($hiddenKeys) {
					return !in_array($k, $hiddenKeys);
				}));
				$colW = ($cw - self::PAD * 3) / 2;
				$itemH = 70;
				$spacingY = 15;
				foreach ($keys as $idx => $key) {
					$col = $idx % 2;
					$row = floor($idx / 2);
					$ix = self::PAD + $col * ($colW + self::PAD);
					$iy = $listTop + 10 + $row * ($itemH + $spacingY) - $this->foxyConfigScrollOffset;
					if ($cx >= $ix && $cx <= $ix + $colW && $cy >= $iy && $cy <= $iy + $itemH) {
						$this->foxyConfigHoverIdx = $idx;
						break;
					}
				}
				break;
			case 4: // Cosmetics
				$this->foxyCosmeticsHoverIdx = -1;
				$sy = self::HEADER_H + self::TAB_H + 60;
				// Skin btn
				if ($cx >= self::PAD + 14 && $cx <= self::PAD + 114 && $cy >= $sy + 32 && $cy <= $sy + 64) {
					$this->foxyCosmeticsHoverIdx = 1;
				}
				// Model btn
				if ($cx >= self::PAD + 124 && $cx <= self::PAD + 244 && $cy >= $sy + 32 && $cy <= $sy + 64) {
					$this->foxyCosmeticsHoverIdx = 0;
				}
				// Browse skin btn hover
				if (($this->foxyConfigData["skinName"] ?? "Default") === "Custom") {
					if ($cx >= self::PAD + 254 && $cx <= self::PAD + 334 && $cy >= $sy + 32 && $cy <= $sy + 64) {
						$this->foxyCosmeticsHoverIdx = 3;
					}
				}

				$cy2 = $sy + 95;
				// Cape btn
				if ($cx >= self::PAD + 14 && $cx <= self::PAD + 114 && $cy >= $cy2 + 32 && $cy <= $cy2 + 64) {
					$this->foxyCosmeticsHoverIdx = 2;
				}
				// Browse cape btn hover
				if (($this->foxyConfigData["capeName"] ?? "None") === "Custom") {
					if ($cx >= self::PAD + 124 && $cx <= self::PAD + 204 && $cy >= $cy2 + 32 && $cy <= $cy2 + 64) {
						$this->foxyCosmeticsHoverIdx = 4;
					}
				}
				break;
			case 5: // OSD
				for ($i = 0; $i < 4; $i++) {
					$ty = 130 + $i * 60;
					if ($cx >= 20 && $cx <= $cw - 20 && $cy >= $ty && $cy <= $ty + 40) {
						$this->foxySettingsHoverIdx = $i;
						return;
					}
				}
				break;
		}
	}

	private function checkFoxyModStatus($forceRemote = false)
	{
		$gameDir = $this->getAbsolutePath($this->settings["game_dir"]);
		$modsDir = $gameDir . DIRECTORY_SEPARATOR . "mods";
		$this->foxyModLocalVersion = null;

		if (is_dir($modsDir)) {
			foreach (scandir($modsDir) as $file) {
				if (preg_match('/^foxyclient-(.+)\.jar$/i', $file, $matches)) {
					$this->foxyModLocalVersion = $matches[1];
					break;
				}
			}
		}

		$now = time();
		if (($forceRemote || ($now - $this->lastFoxyUpdateCheck > 3600)) && !$this->foxyUpdateProcess) {
			$this->lastFoxyUpdateCheck = $now;
			$this->foxyUpdateChannel = new \parallel\Channel(1);
			$this->foxyUpdateProcess = new \parallel\Runtime();
			$cacert = $this->getAbsolutePath(self::CACERT);

			try {
				$this->foxyUpdateFuture = $this->foxyUpdateProcess->run(static function($ch, $cacert) {
					try {
						$url = "https://api.github.com/repos/Minosuko/FoxyClientMod/releases/latest";
						$ch_curl = curl_init($url);
						curl_setopt($ch_curl, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($ch_curl, CURLOPT_USERAGENT, "FoxyClient");
						curl_setopt($ch_curl, CURLOPT_TIMEOUT, 10);
						if (file_exists($cacert)) curl_setopt($ch_curl, CURLOPT_CAINFO, $cacert);
						$resp = curl_exec($ch_curl);
						curl_close($ch_curl);
						if ($resp) {
							$data = json_decode($resp, true);
							if ($data && isset($data["tag_name"])) {
								$ch->send((string)$data["tag_name"]);
								return;
							}
						}
					} catch (\Throwable $e) {}
					$ch->send("");
				}, [$this->foxyUpdateChannel, $cacert]);
				$this->pollEvents->addChannel($this->foxyUpdateChannel);
			} catch (\Throwable $e) { $this->foxyUpdateProcess = null; }
		} else { $this->updateFoxyUpdateFlag(); }
	}

	private function updateFoxyUpdateFlag()
	{
		if ($this->foxyModLocalVersion && $this->foxyModLatestVersion && $this->foxyModLatestVersion !== "") {
			$latest = ltrim($this->foxyModLatestVersion, "vV");
			$local = ltrim($this->foxyModLocalVersion, "vV");
			$this->foxyModUpdateAvailable = (version_compare($latest, $local) > 0);
		} else { $this->foxyModUpdateAvailable = false; }
		$this->needsRedraw = true;
	}

	private function handleFoxyClientSettingsClick($cx, $cy)
	{
		$cw = $this->width - self::SIDEBAR_W;

		// Buttons at Top Right
		$installBtnW = 180;
		$installBtnX = $cw - self::PAD - $installBtnW;
		
		$updateBtnW = 180;
		$updateBtnX = $installBtnX - self::PAD - $updateBtnW;

		// Install FoxyClientMod button
		if ($cx >= $installBtnX && $cx <= $installBtnX + $installBtnW && $cy >= 10 && $cy <= 42) {
			$this->installFoxyClientMod();
			return;
		}

		// Update button (only on Modpack subtab index 0)
		if ($this->foxySubTab === 0) {
			if ($cx >= $updateBtnX && $cx <= $updateBtnX + $updateBtnW && $cy >= 10 && $cy <= 42) {
				$this->startUpdate();
				return;
			}
		}

		// Sub-tabs (6 tabs)
		if ($cy >= self::HEADER_H && $cy <= self::HEADER_H + self::TAB_H) {
			$tabX = self::PAD;
			foreach (["Modpack", "Keybinds", "Macros", "Config", "Cosmetics", "OSD"] as $i => $name) {
				$tw = $this->getTextWidth($name, 1000) + 32;
				if ($cx >= $tabX && $cx <= $tabX + $tw) {
					if ($this->foxySubTab !== $i) {
						$this->foxySubTab = $i;
						$this->scrollOffset = 0;
						$this->scrollTarget = 0;
						$this->hoverModIndex = -1;
						$this->foxyKeybindListenMode = false;
						
						// Trigger mod status check when entering Foxy tab
						$this->checkFoxyModStatus();
					}
					return;
				}
				$tabX += $tw + 8;
			}
		}

		switch ($this->foxySubTab) {
			case 0: // Modpack
				$y = self::HEADER_H + self::TAB_H;
				$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
				$h = $this->height - self::TITLEBAR_H - $footerH - $y;
				if ($cx >= self::PAD && $cx <= $cw - self::PAD && $cy >= $y && $cy <= $y + $h) {
					$mods = &$this->tabs[0]["mods"];
					$itemY = $y + 10 - $this->scrollOffset;
					foreach ($mods as $i => &$mod) {
						if ($cy >= $itemY && $cy < $itemY + self::CARD_H) {
							if ($cx >= $cw - self::PAD * 2 - 50) {
								$mod["checked"] = !$mod["checked"];
								$this->saveConfig();
								return;
							}
						}
						$itemY += self::CARD_H + self::CARD_GAP;
					}
				}
				break;

			case 1: // Keybinds
				// Search bar focus
				$searchW = 250;
				$searchX = $cw - self::PAD - $searchW;
				$searchY = self::HEADER_H + self::TAB_H + 1;
				if ($cx >= $searchX && $cx <= $searchX + $searchW && $cy >= $searchY && $cy <= $searchY + 32) {
					$this->foxyKeybindSearchFocus = true;
					$this->needsRedraw = true;
					return;
				}
				$this->foxyKeybindSearchFocus = false;

				$listTop = self::HEADER_H + self::TAB_H + 68;
				$itemH = 48;
				$localY = $cy - $listTop + $this->foxyKeybindScrollOffset;
				$idx = (int) floor($localY / $itemH);

				$filteredKeys = [];
				foreach (array_keys($this->foxyKeybindData) as $k) {
					if ($this->foxyKeybindSearchQuery === "" || stripos($k, $this->foxyKeybindSearchQuery) !== false) {
						$filteredKeys[] = $k;
					}
				}

				if ($cy >= $listTop && $idx >= 0 && $idx < count($filteredKeys)) {
					$moduleName = $filteredKeys[$idx];
					if ($cx >= $cw - self::PAD - 238 && $cx <= $cw - self::PAD - 50) {
						$this->foxyKeybindEditIdx = $idx;
						$this->foxyKeybindListenMode = true;
						$this->needsRedraw = true;
						return;
					}
					$toggleX = $cw - self::PAD - 50;
					if ($cx >= $toggleX) {
						$this->foxyKeybindData[$moduleName]["enabled"] = !($this->foxyKeybindData[$moduleName]["enabled"] ?? false);
						$this->saveFoxyKeybinds();
						$this->needsRedraw = true;
						return;
					}
				}
				break;

			case 2: // Macros
				$usableH = $this->height - self::TITLEBAR_H;
				$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
				$addBtnY = $usableH - $footerH - 40;
				if ($cy >= $addBtnY && $cy <= $addBtnY + 36 && $cx >= self::PAD && $cx <= self::PAD + 150) {
					$newKey = "0";
					while (isset($this->foxyMacroData[$newKey])) {
						$newKey = (string)((int)$newKey + 1);
					}
					$this->foxyMacroData[$newKey] = "/help";
					$this->saveFoxyMacros();
					$this->needsRedraw = true;
					return;
				}
				$listTop = self::HEADER_H + self::TAB_H + 38;
				$keys = array_keys($this->foxyMacroData);
				$itemH = 44;
				$localY = $cy - $listTop + $this->foxyMacroScrollOffset;
				$idx = (int) floor($localY / $itemH);
				if ($cy >= $listTop && $idx >= 0 && $idx < count($keys)) {
					$delX = $cw - self::PAD - 40;
					if ($cx >= $delX) {
						unset($this->foxyMacroData[$keys[$idx]]);
						$this->saveFoxyMacros();
						$this->needsRedraw = true;
						return;
					}
					// Key badge click - enter listen mode to rebind
					$badgeX = self::PAD + 8;
					if ($cx >= $badgeX && $cx <= $badgeX + 100) {
						$this->foxyMacroEditIdx = $idx;
						$this->foxyMacroListenMode = true;
						$this->needsRedraw = true;
						return;
					}
					// Command click - enter edit mode for text
					$cmdX = self::PAD + 120;
					if ($cx > $badgeX + 100 && $cx < $delX) {
						$this->foxyMacroEditCommandIdx = $idx;
						$this->needsRedraw = true;
						return;
					}
				}
				
				// Deselect if clicking elsewhere in Macros tab
				if ($this->foxyMacroEditCommandIdx !== -1) {
					$this->foxyMacroEditCommandIdx = -1;
					$this->needsRedraw = true;
				}
				break;

			case 3: // Config
				$listTop = self::HEADER_H + self::TAB_H + 10;
				$hiddenKeys = ["skinName", "capeName", "slimModel", "customMusicName", "customFontName", "customBackgroundName", "customSkinPath", "customFontPath", "customBackgroundPath", "customMusicPath"];
				$keys = array_values(array_filter(array_keys($this->foxyConfigData), function($k) use ($hiddenKeys) {
					return !in_array($k, $hiddenKeys);
				}));
				
				$colW = ($cw - self::PAD * 3) / 2;
				$itemH = 70;
				$spacingY = 15;
				
				foreach ($keys as $idx => $key) {
					$col = $idx % 2;
					$row = floor($idx / 2);
					$ix = self::PAD + $col * ($colW + self::PAD);
					$iyCard = $listTop + 10 + $row * ($itemH + $spacingY) - $this->foxyConfigScrollOffset;
					
					if ($cx >= $ix && $cx <= $ix + $colW && $cy >= $iyCard && $cy <= $iyCard + $itemH) {
						if (is_bool($this->foxyConfigData[$key])) {
							$this->foxyConfigData[$key] = !$this->foxyConfigData[$key];
							$this->saveFoxyConfig();
							$this->needsRedraw = true;
							return;
						} else if ($key === "customFontType" || $key === "customBackgroundType" || $key === "bgMusicType") {
							$tw = 100;
							$tx = $ix + $colW - 16 - $tw;
							// Click on cycle button
							if ($cx >= $tx && $cx <= $tx + $tw) {
								$curr = $this->foxyConfigData[$key] ?? "Default";
								if ($key === "bgMusicType") {
									$next = $curr === "Default" ? "Custom" : "Default";
								} else {
									if ($curr === "Default") $next = "Custom";
									elseif ($curr === "Custom") $next = "FoxyClient";
									else $next = "Default";
								}
								$this->foxyConfigData[$key] = $next;
								$this->saveFoxyConfig();
								$this->needsRedraw = true;
								return;
							}
							// Click on Browse button
							if ($this->foxyConfigData[$key] === "Custom") {
								$bx = $tx - 80 - 10;
								if ($cx >= $bx && $cx <= $bx + 80) {
									if ($key === "customFontType") {
										$filter = "Font Files\0*.ttf;*.otf\0All Files\0*.*\0";
										$filename = "custom_font.";
									} else if ($key === "bgMusicType") {
										$filter = "Music Files\0*.mp3;*.ogg;*.wav\0All Files\0*.*\0";
										$filename = "background_music.";
									} else {
										$filter = "Image Files\0*.png;*.jpg;*.jpeg\0All Files\0*.*\0";
										$filename = "custom_background.";
									}
									
									$chosen = $this->openFileChooser($filter);
									if ($chosen) {
										$gameDir = $this->getAbsolutePath($this->settings["game_dir"] ?? ".");
										$ext = pathinfo($chosen, PATHINFO_EXTENSION);
										$dest = $gameDir . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "foxyclient" . DIRECTORY_SEPARATOR . $filename . $ext;
										$dir = dirname($dest);
										if (!is_dir($dir)) mkdir($dir, 0777, true);
										@copy($chosen, $dest);
										
										if ($key === "bgMusicType") {
											$this->foxyConfigData["customMusicPath"] = $dest;
											$this->foxyConfigData["customMusicName"] = basename($chosen);
										} else if ($key === "customFontType") {
											$this->foxyConfigData["customFontPath"] = $dest;
											$this->foxyConfigData["customFontName"] = basename($chosen);
										} else if ($key === "customBackgroundType") {
											$this->foxyConfigData["customBackgroundPath"] = $dest;
											$this->foxyConfigData["customBackgroundName"] = basename($chosen);
										}
										$this->saveFoxyConfig();
									}
									$this->needsRedraw = true;
									return;
								}
							}
						}
					}
				}
				break;

			case 4: // Cosmetics
				$sy = self::HEADER_H + self::TAB_H + 60;
				// Skin btn
				if ($cx >= self::PAD + 14 && $cx <= self::PAD + 114 && $cy >= $sy + 32 && $cy <= $sy + 64) {
					$curr = $this->foxyConfigData["skinName"] ?? "Default";
					$this->foxyConfigData["skinName"] = $curr === "Default" ? "Custom" : "Default";
					$this->saveFoxyConfig();
					$this->needsRedraw = true;
					return;
				}
				// Model btn
				if ($cx >= self::PAD + 124 && $cx <= self::PAD + 244 && $cy >= $sy + 32 && $cy <= $sy + 64) {
					$this->foxyConfigData["slimModel"] = !($this->foxyConfigData["slimModel"] ?? false);
					$this->saveFoxyConfig();
					$this->needsRedraw = true;
					return;
				}
				// Browse skin btn
				if (($this->foxyConfigData["skinName"] ?? "Default") === "Custom") {
					if ($cx >= self::PAD + 254 && $cx <= self::PAD + 334 && $cy >= $sy + 32 && $cy <= $sy + 64) {
						$chosen = $this->openFileChooser("PNG Files\0*.png\0All Files\0*.*\0");
						if ($chosen) {
							$gameDir = $this->getAbsolutePath($this->settings["game_dir"] ?? ".");
							$dest = $gameDir . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "foxyclient" . DIRECTORY_SEPARATOR . "custom_skin.png";
							$dir = dirname($dest);
							if (!is_dir($dir)) mkdir($dir, 0777, true);
							@copy($chosen, $dest);
							$this->foxyConfigData["skinName"] = "Custom";
							$this->saveFoxyConfig();
							$this->foxySkinTexPathCache = null; // Force reload
						}
						$this->needsRedraw = true;
						return;
					}
				}

				$cy2 = $sy + 95;
				// Cape btn
				if ($cx >= self::PAD + 14 && $cx <= self::PAD + 114 && $cy >= $cy2 + 32 && $cy <= $cy2 + 64) {
					$curr = $this->foxyConfigData["capeName"] ?? "None";
					if ($curr === "None") $next = "Default";
					elseif ($curr === "Default") $next = "Custom";
					else $next = "None"; // Custom -> None
					$this->foxyConfigData["capeName"] = $next;
					$this->saveFoxyConfig();
					$this->needsRedraw = true;
					return;
				}
				// Browse cape btn
				if (($this->foxyConfigData["capeName"] ?? "None") === "Custom") {
					if ($cx >= self::PAD + 124 && $cx <= self::PAD + 204 && $cy >= $cy2 + 32 && $cy <= $cy2 + 64) {
						$chosen = $this->openFileChooser("PNG Files\0*.png\0All Files\0*.*\0");
						if ($chosen) {
							$gameDir = $this->getAbsolutePath($this->settings["game_dir"] ?? ".");
							$dest = $gameDir . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "foxyclient" . DIRECTORY_SEPARATOR . "custom_cape.png";
							$dir = dirname($dest);
							if (!is_dir($dir)) mkdir($dir, 0777, true);
							@copy($chosen, $dest);
							$this->foxyConfigData["capeName"] = "Custom";
							$this->saveFoxyConfig();
							$this->foxyCapeTexPathCache = null; // Force reload
						}
						$this->needsRedraw = true;
						return;
					}
				}

				// Preview Box Drag Start
				$previewW = min(300, $cw - self::PAD * 2);
				$previewX = ($cw - $previewW) / 2;
				$previewY = $cy2 + 80;
				// Handle dynamic H just for boundary check
				$yOrig = self::HEADER_H + self::TAB_H;
				$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
				$usableH = $this->height - self::TITLEBAR_H;
				$hOrig = $usableH - $footerH - $yOrig;
				$previewH = min(280, $hOrig - ($previewY - $yOrig) - 20);

				if ($cx >= $previewX && $cx <= $previewX + $previewW && $cy >= $previewY && $cy <= $previewY + $previewH) {
					$this->previewDragging = true;
					$this->previewLastMouseX = $this->mouseX;
					$this->previewLastMouseY = $this->mouseY;
				}
				break;

			case 5: // OSD
				for ($i = 0; $i < 4; $i++) {
					$ty = 130 + $i * 60;
					if ($cx >= 20 && $cx <= $cw - 20 && $cy >= $ty && $cy <= $ty + 40) {
						$key = ["overlay_cpu", "overlay_gpu", "overlay_ram", "overlay_vram"][$i];
						$this->settings[$key] = !($this->settings[$key] ?? false);
						$this->saveSettings();
						return;
					}
				}
				break;
		}
	}

	private function computeModsPageHover($cx, $cy)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$this->subTabHoverIdx = -1;
		$this->tabHover = -1;
		$this->hoverModIndex = -1;
		$this->modsVerHoverIdx = -1;
		$this->modsFilterHoverIdx = -1;

		// Filter Dropdown Hover (Highest Z-Index)
		if ($this->modsFilterDropdown !== "") {
			$key = $this->modsFilterDropdown;
			$pillRect = $this->modsFilterPillRects[$key] ?? null;
			if ($pillRect) {
				$ddX = $pillRect[0];
				$ddY = $pillRect[1] + $pillRect[3] + 4;
				$ddW = $key === "env" ? 150 : 220;
				
				$itemsCount = 0;
				if ($key === "category") {
					$itemsCount = count($this->modsCategories) + 1;
				} elseif ($key === "loader") {
					$itemsCount = count($this->modsLoaderList) + 1;
				} elseif ($key === "env") {
					$itemsCount = 3;
				} elseif ($key === "version") {
					$releaseVersions = [];
					foreach ($this->versions as $v) {
						if (($v["type"] ?? "") === "release") {
							$releaseVersions[] = $v["id"];
						}
					}
					if (empty($releaseVersions)) {
						$releaseVersions = [$this->config["minecraft_version"]];
					}
					$itemsCount = count($releaseVersions);
				}

				if ($itemsCount > 0) {
					$itemH = 30;
					$maxVisible = min(10, $itemsCount);
					$fullH = $maxVisible * $itemH;

					if ($cx >= $ddX && $cx <= $ddX + $ddW && $cy >= $ddY && $cy <= $ddY + $fullH) {
						$localY = $cy - $ddY + $this->modsFilterScrollOffset;
						$idx = (int) floor($localY / $itemH);
						if ($idx >= 0 && $idx < $itemsCount) {
							$this->modsFilterHoverIdx = $idx;
						}
					}
				}
			}
			return; // Don't process grid hover when dropdown is open
		}

		// Sub-tabs (Mods, Modpacks, Installed)
		if ($cy >= self::HEADER_H && $cy <= self::HEADER_H + self::TAB_H) {
			$tabX = self::PAD;
			foreach (["Mods", "Modpacks", "Installed"] as $i => $name) {
				$tw = $this->getTextWidth($name, 1000) + 32;
				if ($cx >= $tabX && $cx <= $tabX + $tw) {
					$this->subTabHoverIdx = 200 + $i;
					return;
				}
				$tabX += $tw + 8;
			}
		}

		$y = self::HEADER_H + self::TAB_H;
		$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
		$h = $this->height - self::TITLEBAR_H - $footerH - $y;

		if ($this->modpackSubTab === 2) {
			// Installed tab hover
			$this->modpackUninstallHover = -1;
			$itemY = $y + 10 - $this->scrollOffset;
			if ($this->isInstallingModpack || $this->modpackInstallProgress !== "") {
				$itemY += 46;
			}
			$idx = 0;
			foreach ($this->installedModpacks as $slug => $pack) {
				$cardH = 72;
				if ($cx >= self::PAD && $cx <= $cw - self::PAD && $cy >= $itemY && $cy < $itemY + $cardH) {
					$this->modpackUninstallHover = $idx;
					return;
				}
				$itemY += $cardH + 8;
				$idx++;
			}
			return;
		}

		if (
			$cx >= self::PAD &&
			$cx <= $cw - self::PAD &&
			$cy >= $y &&
			$cy <= $y + $h
		) {
			// Grid logic for Modrinth
			$alpha = $this->modrinthAnim;
			$slideY = (1.0 - $alpha) * 20;
			$gridX = self::PAD;
			$gridY = $y + 10 - $this->scrollOffset + $slideY;
			$cardW = ($cw - self::PAD * 3) / 2;
			$cardH = 110;
			$gap = 12;

			foreach ($this->modrinthSearchResults as $i => $hit) {
				$col = $i % 2;
				$row = floor($i / 2);
				$itemX = $gridX + $col * ($cardW + $gap);
				$itemY = $gridY + $row * ($cardH + $gap);

				if (
					$cx >= $itemX &&
					$cx <= $itemX + $cardW &&
					$cy >= $itemY &&
					$cy <= $itemY + $cardH
				) {
					$this->hoverModIndex = $i;
					return;
				}
			}
		}
	}
	private function computePropertiesPageHover($cx, $cy)
	{
		$this->propTabHover = -1;
		$this->propFieldHover = -1;

		// Sub-tabs hover (Y=70, H=40)
		if ($cy >= self::HEADER_H && $cy < self::HEADER_H + self::TAB_H) {
			$tx = self::PAD;
			$cats = ["Minecraft", "Launcher", "Update", "About"];
			foreach ($cats as $i => $cat) {
				$tw = strlen($cat) * 8 + 30;
				if ($cx >= $tx && $cx < $tx + $tw) {
					$this->propTabHover = $i;
					break;
				}
				$tx += $tw + 4;
			}
		}

		// Content area hover
		$contentTop = self::HEADER_H + self::TAB_H + 20;
		$rowOffset = ($this->propSubTab === 2 && $this->updateMessage !== "") ? 50 : 0;
		$localY = $cy - $contentTop + $this->propScrollOffset - $rowOffset;

		if ($cy >= $contentTop + $rowOffset) {
			$rowH = 60;
			$idx = (int) floor($localY / $rowH);
			if ($idx >= 0 && $idx <= 8) {
				$this->propFieldHover = $idx;
			}
			
			// Additional logic for Update tab specific button hovers
			if ($this->propSubTab === 2) {
				$cw = $this->width - self::SIDEBAR_W;
				$bx = $cw - self::PAD - 200;
				$bw = 200;
				if ($cx >= $bx && $cx <= $bx + $bw) {
					// We reuse idx from the row calc, but make sure it corresponds to our 2 buttons
					if ($idx === 0 || $idx === 1) {
						$this->propFieldHover = "btn_" . $idx;
					}
				}
			}
		}

		// Fixed buttons hover
		$this->propResetHover = false;
		$this->propSignOutHover = false;
		$cw = $this->width - self::SIDEBAR_W;
		$usableH = $this->height - self::TITLEBAR_H;
		$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
		$btnAreaY = self::TITLEBAR_H + $usableH - $footerH - 60;
		$btnW = 160;
		$btnH = 36;
		$btnGap = 20;
		$totalW = ($btnW * 2) + $btnGap;
		$startX = self::SIDEBAR_W + ($cw - $totalW) / 2;

		if ($this->mouseY >= $btnAreaY + 12 && $this->mouseY <= $btnAreaY + 12 + $btnH) {
			if ($this->mouseX >= $startX && $this->mouseX <= $startX + $btnW) {
				$this->propResetHover = true;
			} elseif ($this->mouseX >= $startX + $btnW + $btnGap && $this->mouseX <= $startX + $btnW + $btnGap + $btnW) {
				$this->propSignOutHover = true;
			}
		}

		// Font dropdown hover
		$this->propFontDropdownHover = -1;
		if ($this->propFontDropdownOpen !== "" && $this->propSubTab === 1) {
			$cw = $this->width - self::SIDEBAR_W;
			$ddX = $cw - self::PAD - 300;
			$ddW = 300;
			$rowIdx = $this->propFontDropdownOpen === "launcher" ? 4 : 5;
			$ddY =
				$contentTop +
				($rowIdx + 1) * 60 -
				(int) $this->propScrollOffset;
			$fonts = $this->availableFonts;
			$itemH = 32;
			$ddH = count($fonts) * $itemH;
			if (
				$cx >= $ddX &&
				$cx <= $ddX + $ddW &&
				$cy >= $ddY &&
				$cy <= $ddY + $ddH
			) {
				$this->propFontDropdownHover = (int) floor(
					($cy - $ddY) / $itemH,
				);
			}
		}

		// About tab links hover
		$this->aboutDonateHover = false;
		$this->aboutGithubHover = false;
		$this->aboutWebsiteHover = false;
		$this->aboutContactHover = false;

		if ($this->propSubTab === 3) {
			$contentTop = self::HEADER_H + self::TAB_H + 20;
			$usableY = $this->mouseY - self::TITLEBAR_H - $contentTop + $this->propScrollOffset;
			$usableX = $this->mouseX - self::SIDEBAR_W; // Local X relative to Content Area

			// Calculate widths (matching renderPropertiesAbout)
			$baseW = $this->getTextWidth("- Donate: ", 1000);
			$wDonate = $baseW + $this->getTextWidth("https://ko-fi.com/minosuko", 1000);
			
			$baseW = $this->getTextWidth("- GitHub: ", 1000);
			$wGithub = $baseW + $this->getTextWidth("https://github.com/Minosuko/FoxyClient", 1000);

			$baseW = $this->getTextWidth("- Website: ", 1000);
			$wWebsite = $baseW + $this->getTextWidth("https://foxyclient.qzz.io", 1000);

			$baseW = $this->getTextWidth("- Contact: ", 1000);
			$wContact = $baseW + $this->getTextWidth("https://github.com/Minosuko/FoxyClient/issues", 1000);

			if ($usableY >= 370 && $usableY < 390 && $usableX >= self::PAD + 10 && $usableX <= self::PAD + 10 + $wDonate) {
				$this->aboutDonateHover = true;
			} elseif ($usableY >= 395 && $usableY < 415 && $usableX >= self::PAD + 10 && $usableX <= self::PAD + 10 + $wGithub) {
				$this->aboutGithubHover = true;
			} elseif ($usableY >= 420 && $usableY < 440 && $usableX >= self::PAD + 10 && $usableX <= self::PAD + 10 + $wWebsite) {
				$this->aboutWebsiteHover = true;
			} elseif ($usableY >= 445 && $usableY < 465 && $usableX >= self::PAD + 10 && $usableX <= self::PAD + 10 + $wContact) {
				$this->aboutContactHover = true;
			}
		}
	}

	private function handlePropertiesPageClick($cx, $cy)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$usableH = $this->height - self::TITLEBAR_H;
		$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
		$btnAreaY = $usableH - $footerH - 60;
		$btnW = 160;
		$btnH = 36;
		$btnGap = 20;
		$totalW = ($btnW * 2) + $btnGap;
		$startX = ($cw - $totalW) / 2;

		// Fixed Buttons Click Area
		if ($cy >= $btnAreaY + 12 && $cy <= $btnAreaY + 12 + $btnH) {
			if ($cx >= $startX && $cx <= $startX + $btnW) {
				// Reset Settings
				$this->settings = $this->defaultSettings;
				$this->saveSettings();
				$this->colors = $this->settings["theme"] === "dark" ? $this->darkColors : $this->lightColors;
				$this->loadBackground();
				return;
			} elseif ($cx >= $startX + $btnW + $btnGap && $cx <= $startX + $btnW + $btnGap + $btnW) {
				// Sign Out
				$this->activeAccount = null;
				$this->config["active_account"] = null;
				$this->saveConfig();
				return;
			}
		}

		// Sub-tabs click
		if ($cy >= self::HEADER_H && $cy < self::HEADER_H + self::TAB_H) {
			$tx = self::PAD;
			$cats = ["Minecraft", "Launcher", "Update", "About"];
			foreach ($cats as $i => $cat) {
				$tw = strlen($cat) * 8 + 30;
				if ($cx >= $tx && $cx < $tx + $tw) {
					$this->propSubTab = $i;
					$this->propScrollTarget = 0;
					$this->propScrollOffset = 0;
					$this->propActiveField = "";
					return;
				}
				$tx += $tw + 4;
			}
		}

		// Content clicks
		$contentTop = self::HEADER_H + self::TAB_H + 20;
		$rowOffset = ($this->propSubTab === 2 && $this->updateMessage !== "") ? 50 : 0;
		if ($cy < $contentTop + $rowOffset) {
			return;
		}

		$localY = $cy - $contentTop + $this->propScrollOffset - $rowOffset;
		$rowH = 60;
		$idx = (int) floor($localY / $rowH);

		$cw = $this->width - self::SIDEBAR_W;
		$fieldX = $cw - self::PAD - 300;
		$fieldW = 300;

		// Scrollbar interaction
		$maxScroll = 200; // Hardcoded max in existing logic
		$viewH = $this->height - self::TITLEBAR_H - 120; // Matches renderProperties
		$barX = $cw - 10;
		if ($cx >= $barX && $cx <= $cw) {
			$thumbSize = max(30, $viewH * ($viewH / ($maxScroll + $viewH)));
			$thumbPos =
				($viewH - $thumbSize) * ($this->propScrollOffset / $maxScroll);
			$absThumbY = self::HEADER_H + self::TAB_H + 20 + $thumbPos;
			if ($cy >= $absThumbY && $cy <= $absThumbY + $thumbSize) {
				$this->isDraggingScroll = true;
				$this->dragType = "prop";
				$this->dragStartY = $this->mouseY;
				$this->dragStartOffset = $this->propScrollOffset;
				return;
			}
		}

		$this->propActiveField = ""; // Reset by default

		if ($this->propSubTab === 0) {
			// Minecraft Config
			if ($idx === 0) {
				// Game Dir
				// Folder Browse
				$bx = $cw - self::PAD - 80;
				$bw = 80;
				if (
					$cx >= $bx &&
					$cx <= $bx + $bw &&
					$cy >=
						$contentTop + $idx * $rowH - $this->propScrollOffset &&
					$cy <=
						$contentTop +
							$idx * $rowH +
							40 -
							$this->propScrollOffset
				) {
					$bi = $this->shell32->new("BROWSEINFOA");
					FFI::memset(FFI::addr($bi), 0, FFI::sizeof($bi));
					$bi->hwndOwner = $this->hwnd;

					$displayBuf = FFI::new("char[260]");
					$bi->pszDisplayName = FFI::cast("char*", $displayBuf);

					$title = "Select Game Folder";
					$titleBuf = FFI::new("char[" . (strlen($title) + 1) . "]");
					FFI::memcpy($titleBuf, $title, strlen($title));
					$bi->lpszTitle = FFI::cast("char*", $titleBuf);

					$initialPath = $this->getAbsolutePath(
						$this->settings["game_dir"],
					);
					$initialPathBuf = FFI::new(
						"char[" . (strlen($initialPath) + 1) . "]",
					);
					FFI::memcpy(
						$initialPathBuf,
						$initialPath,
						strlen($initialPath),
					);

					$bi->lpfn = function ($hwnd, $msg, $lp, $data) use (
						$initialPathBuf,
					) {
						try {
							if ($msg === 1) {
								// BFFM_INITIALIZED
								$this->user32->SendMessageA(
									$hwnd,
									0x466,
									1,
									$initialPathBuf,
								); // BFFM_SETSELECTIONA
							}
						} catch (\Throwable $e) {
							$this->log(
								"Error in BrowseFolder callback: " .
									$e->getMessage(),
								"ERROR",
							);
						}
						return 0;
					};

					$bi->ulFlags = 0x01 | 0x00000040 | 0x00000010; // BIF_RETURNONLYFSDIRS | BIF_NEWDIALOGSTYLE | BIF_EDITBOX
					$pidl = $this->shell32->SHBrowseForFolderA(FFI::addr($bi));
					if ($pidl) {
						$pathBuf = FFI::new("char[260]");
						if (
							$this->shell32->SHGetPathFromIDListA(
								$pidl,
								$pathBuf,
							)
						) {
							$this->settings["game_dir"] = FFI::string($pathBuf);
							$this->saveSettings();
						}
					}
					return;
				}
				if ($cx >= $fieldX && $cx <= $fieldX + $fieldW) {
					$this->propActiveField = "game_dir";
				}
			} elseif ($idx === 1) {
				// Window Size
				if ($cx >= $fieldX && $cx <= $fieldX + 130) {
					$this->propActiveField = "window_w";
				}
				if ($cx >= $fieldX + 170 && $cx <= $fieldX + 300) {
					$this->propActiveField = "window_h";
				}
			} elseif ($idx === 2) {
				// Java Path
				if ($cx >= $fieldX && $cx <= $fieldX + $fieldW) {
					$this->javaModalOpen = true;
					$this->javaModalActiveField = "";
					$this->javaModalDropdownOpen = false;
				}
			} elseif ($idx === 3) {
				// RAM MB
				// Buttons: -256 at fieldX, +256 at fieldX + 260
				$cur = (int) $this->settings["ram_mb"];
				if ($cx >= $fieldX && $cx <= $fieldX + 40) {
					$this->settings["ram_mb"] = max(512, $cur - 256);
					$this->saveSettings();
				} elseif ($cx >= $fieldX + 260 && $cx <= $fieldX + 300) {
					$this->settings["ram_mb"] = min(32768, $cur + 256);
					$this->saveSettings();
				} elseif ($cx >= $fieldX + 50 && $cx <= $fieldX + 250) {
					$this->propActiveField = "ram_mb";
				}
			} elseif ($idx >= 4 && $idx <= 7) {
				// Open folder buttons
				$bx = $fieldX + 100;
				$bw = 200;
				if ($cx >= $bx && $cx <= $bx + $bw) {
					$gameDir = $this->getAbsolutePath($this->settings["game_dir"]);
					$subDirs = [
						4 => '',
						5 => 'mods',
						6 => 'resourcepacks',
						7 => 'shaderpacks',
					];
					$target = $gameDir;
					if (!empty($subDirs[$idx])) {
						$target .= DIRECTORY_SEPARATOR . $subDirs[$idx];
					}
					if (!is_dir($target)) {
						@mkdir($target, 0777, true);
					}
					pclose(popen('explorer "' . str_replace('/', '\\', $target) . '"', 'r'));
				}
			}
		} elseif ($this->propSubTab === 1) {
			// Launcher Config
			// Font dropdown item click interception
			if ($this->propFontDropdownOpen !== "") {
				$cw = $this->width - self::SIDEBAR_W;
				$ddX = $cw - self::PAD - 300;
				$ddW = 300;
				$rowIdx = $this->propFontDropdownOpen === "launcher" ? 4 : 5;
				$contentTop = self::HEADER_H + self::TAB_H + 20;
				$ddY =
					$contentTop +
					($rowIdx + 1) * 60 -
					(int) $this->propScrollOffset;
				$fonts = $this->availableFonts;
				$itemH = 32;
				$ddH = count($fonts) * $itemH;

				if (
					$cx >= $ddX &&
					$cx <= $ddX + $ddW &&
					$cy >= $ddY &&
					$cy <= $ddY + $ddH
				) {
					$clickedFi = (int) floor(($cy - $ddY) / $itemH);
					if (isset($fonts[$clickedFi])) {
						$selectedFont = $fonts[$clickedFi];
						if ($this->propFontDropdownOpen === "launcher") {
							$this->settings["font_launcher"] = $selectedFont;
							$this->saveSettings();
							$this->fontAtlas = [];
							$this->initFonts();
						} else {
							$this->settings["font_overlay"] = $selectedFont;
							$this->saveSettings();
							if ($this->overlayChannel) {
								try {
									$this->overlayChannel->send([
										"font_overlay" => $selectedFont,
									]);
								} catch (\Throwable $e) {
								}
							}
						}
						$this->propFontDropdownOpen = "";
						return;
					}
				}
				// Click outside dropdown closes it
				$this->propFontDropdownOpen = "";
				return;
			}

			if ($idx === 0) {
				// Background - open modal
				if ($cx >= $fieldX && $cx <= $fieldX + $fieldW) {
					$this->bgModalOpen = true;
					$this->bgModalActiveField = "";
				}
			} elseif ($idx === 1) {
				// Theme
				if ($cx >= $fieldX && $cx <= $fieldX + $fieldW) {
					$this->settings["theme"] =
						$this->settings["theme"] === "dark" ? "light" : "dark";
					$this->colors =
						$this->settings["theme"] === "dark"
							? $this->darkColors
							: $this->lightColors;
					$this->saveSettings();
				}
			} elseif ($idx === 2) {
				// Language
				// Currently only English, clicking does nothing
			} elseif ($idx === 3) {
				// Show Modified Versions
				if ($cx >= $fieldX && $cx <= $fieldX + $fieldW) {
					$this->settings["show_modified_versions"] = !(
						$this->settings["show_modified_versions"] ?? false
					);
					$this->saveSettings();
					$this->filteredVersionsCache = null; // Refresh immediately
				}
			} elseif ($idx === 4) {
				// Launcher Font
				if ($cx >= $fieldX && $cx <= $fieldX + $fieldW) {
					$this->propFontDropdownOpen =
						$this->propFontDropdownOpen === "launcher"
							? ""
							: "launcher";
					$this->propFontDropdownHover = -1;
				}
			} elseif ($idx === 5) {
				// Overlay Font
				if ($cx >= $fieldX && $cx <= $fieldX + $fieldW) {
					$this->propFontDropdownOpen =
						$this->propFontDropdownOpen === "overlay"
							? ""
							: "overlay";
					$this->propFontDropdownHover = -1;
				}
			} elseif ($idx === 6) {
				// Separate Modpack Folder
				if ($cx >= $fieldX && $cx <= $fieldX + $fieldW) {
					$this->settings["separate_modpack_folder"] = !(
						$this->settings["separate_modpack_folder"] ?? false
					);
					$this->saveSettings();
				}
			} elseif ($idx === 7) {
				// Reset Settings
				if ($cx >= $fieldX + 100 && $cx <= $fieldX + 300) {
					$this->settings = $this->defaultSettings;
					$this->saveSettings();
					$this->colors = $this->settings["theme"] === "dark" ? $this->darkColors : $this->lightColors;
					$this->loadBackground();
				}
			} elseif ($idx === 8) {
				// Sign Out
				if ($cx >= $fieldX + 100 && $cx <= $fieldX + 300) {
					$this->activeAccount = null;
					$this->config["active_account"] = null;
					$this->saveConfig();
				}
			} else {
				$this->propFontDropdownOpen = "";
			}
		} elseif ($this->propSubTab === 3) {
			// About tab links
			if ($this->aboutDonateHover) $this->openUrl("https://ko-fi.com/minosuko");
			elseif ($this->aboutGithubHover) $this->openUrl("https://github.com/Minosuko/FoxyClient");
			elseif ($this->aboutWebsiteHover) $this->openUrl("https://foxyclient.qzz.io");
			elseif ($this->aboutContactHover) $this->openUrl("https://github.com/Minosuko/FoxyClient/issues");
		} elseif ($this->propSubTab === 2) {
			// Update tab
			$cw = $this->width - self::SIDEBAR_W;
			$bx = $cw - self::PAD - 200;
			$bw = 200;
			if ($cx >= $bx && $cx <= $bx + $bw) {
				// Button bounds check relative to adjusted row index
				$clickY = $localY - ($idx * $rowH);
				if ($clickY >= 10 && $clickY <= 50) {
					// "Check for FoxyClient Update"
					if ($idx === 0) {
						if (strpos($this->updateMessage, "New version available") !== false) {
							$this->performSelfUpdate();
						} else {
							$this->triggerCheckForUpdate(false);
						}
					}
				// "Check CA Cert Update"
				elseif ($idx === 1 && !$this->isUpdatingCacert) {
					$this->isUpdatingCacert = true;
					$this->updateMessage = "Downloading complete cacert.pem from curl.se...";
					
					if (!isset($this->pollEvents)) {
						return;
					}

					if (!$this->updateChannel) {
						$this->updateChannel = new \parallel\Channel(1024);
						$this->pollEvents->addChannel($this->updateChannel);
					}
					$ch = $this->updateChannel;
					
					$proc = new \parallel\Runtime();
					$cacertPath = __DIR__ . DIRECTORY_SEPARATOR . self::CACERT;
					
					$f = $proc->run(function(\parallel\Channel $ch, $cacertPath) {
						try {
							$url = "https://curl.se/ca/cacert.pem";
							
							// Step 1: HEAD request to get file size
							$head = curl_init($url);
							curl_setopt($head, CURLOPT_NOBODY, true);
							curl_setopt($head, CURLOPT_RETURNTRANSFER, true);
							curl_setopt($head, CURLOPT_SSL_VERIFYPEER, false);
							curl_setopt($head, CURLOPT_TIMEOUT, 10);
							curl_setopt($head, CURLOPT_FOLLOWLOCATION, true);
							curl_exec($head);
							$totalSize = (int) curl_getinfo($head, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
							curl_close($head);
							
							if ($totalSize <= 0) {
								$totalSize = 225076; // Known approximate size as fallback
							}
							
							// Step 2: Download with WRITEFUNCTION for chunk-by-chunk progress
							$curl = curl_init($url);
							curl_setopt($curl, CURLOPT_TIMEOUT, 30);
							curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
							curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
							curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
							curl_setopt($curl, CURLOPT_USERAGENT, "FoxyClient-CAUpdater");
							
							$buffer = '';
							$downloaded = 0;
							$lastPct = -1;
							
							curl_setopt($curl, CURLOPT_WRITEFUNCTION, function($curl, $chunk) use ($ch, &$buffer, &$downloaded, &$lastPct, $totalSize) {
								$len = strlen($chunk);
								$buffer .= $chunk;
								$downloaded += $len;
								
								$pct = (int) min(99, floor(($downloaded / $totalSize) * 100));
								if ($pct !== $lastPct) {
									$ch->send(['type' => 'ca_update_progress', 'pct' => $pct]);
									$lastPct = $pct;
								}
								return $len; // MUST return length to continue download
							});
							
							curl_exec($curl);
							$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
							$curlErr = curl_error($curl);
							curl_close($curl);
							
							if ($downloaded === 0 || $curlErr) {
								$ch->send(['type' => 'ca_update_err', 'msg' => "Download failed: $curlErr"]);
								return;
							}

							if ($code === 200 && $buffer && strpos($buffer, 'CERTIFICATE') !== false) {
								if (@file_put_contents($cacertPath, $buffer) === false) {
									$ch->send(['type' => 'ca_update_err', 'msg' => "Failed to write to config/cacert.pem"]);
								} else {
									$ch->send(['type' => 'ca_update_progress', 'pct' => 100]);
									$ch->send(['type' => 'ca_update_ok']);
								}
							} else {
								$ch->send(['type' => 'ca_update_err', 'msg' => "HTTP $code - Invalid CA data."]);
							}
						} catch (\Throwable $e) {
							$ch->send(['type' => 'ca_update_err', 'msg' => "Crash: " . $e->getMessage()]);
						}
					}, [$ch, $cacertPath]);
					
					$this->pendingFutures[] = $f;
				}
			}
		}
	}
}

	// ─── Rendering ───
	private function render()
	{
		$gl = $this->opengl32;
		$gl->glClearColor(
			$this->colors["bg"][0],
			$this->colors["bg"][1],
			$this->colors["bg"][2],
			1.0,
		);
		$gl->glClear(0x00004000);

		// --- Boot-up Sequence Calculations ---
		$wA = $this->windowAnim;

		// Background fade (fastest)
		$bgAlpha = min(1.0, $wA * 4.0);
		$this->globalAlpha = $bgAlpha;

		// Background Image (always full screen)
		if ($this->bgTex) {
			$gl->glColor4f(1, 1, 1, 1);
			$blur = (int) ($this->settings["bg_blur"] ?? 0);
			$u1 = 0;
			$v1 = 0;
			$u2 = 1;
			$v2 = 1;
			if ($this->bgW > 0 && $this->bgH > 0) {
				$winAspect = $this->width / $this->height;
				$imgAspect = $this->bgW / $this->bgH;
				if ($winAspect > $imgAspect) {
					$heightInImg = $this->bgW / $winAspect;
					$v1 = ($this->bgH - $heightInImg) / 2 / $this->bgH;
					$v2 = 1.0 - $v1;
				} else {
					$widthInImg = $this->bgH * $winAspect;
					$u1 = ($this->bgW - $widthInImg) / 2 / $this->bgW;
					$u2 = 1.0 - $u1;
				}
			}
			$uvs = [$u1, $v1, $u2, $v2];

			if ($blur > 0) {
				$this->drawTexture(
					$this->bgTex,
					0,
					0,
					$this->width,
					$this->height,
					[1, 1, 1, $bgAlpha],
					$uvs,
				);
			} else {
				$this->drawTexture(
					$this->bgTex,
					0,
					0,
					$this->width,
					$this->height,
					[1, 1, 1, $bgAlpha],
					$uvs,
				);
			}
			$this->drawRect(0, 0, $this->width, $this->height, [
				0,
				0,
				0,
				0.4 * $bgAlpha,
			]);
		} else {
			$this->drawRect(0, 0, $this->width, $this->height, [
				$this->colors["bg"][0],
				$this->colors["bg"][1],
				$this->colors["bg"][2],
				$bgAlpha,
			]);
		}

		// --- PHASE 1: Splash Screen (Logo + Loading Bar) ---
		$splashAlpha = 0.0;
		if ($wA < 0.6) {
			$splashAlpha = min(1.0, $wA * 5.0); // fast fade in
			if ($wA > 0.4) {
				$splashAlpha = 1.0 - ($wA - 0.4) * 6.0;
			} // fast fade out
			$splashAlpha = max(0.0, $splashAlpha);
		}

		if ($splashAlpha > 0.001) {
			$this->globalAlpha = $splashAlpha * $bgAlpha;
			$logS = 100;
			$this->drawTexture(
				$this->logoTex,
				($this->width - $logS) / 2,
				($this->height - $logS) / 2 - 20,
				$logS,
				$logS,
			);

			$barW = 180;
			$barH = 2;
			$barX = ($this->width - $barW) / 2;
			$barY = $this->height / 2 + 60;
			$this->drawRect($barX, $barY, $barW, $barH, [0.1, 0.1, 0.1, 0.5]); // track
			$splashNorm = min(1.0, $wA / 0.4);
			// Smooth ease-out cubic for the bar
			$loadProg = 1.0 - pow(1.0 - $splashNorm, 3);
			$this->drawRect(
				$barX,
				$barY,
				$barW * $loadProg,
				$barH,
				$this->colors["primary"],
			); // fill
		}

		// --- PHASE 2: UI Reveal (Main interface) ---
		if ($wA > 0.4) {
			$revWA = ($wA - 0.4) / 0.6; // 0 to 1 over the second phase

			// Sidebar: Slide in from left
			$sideProgress = max(0.0, min(1.0, ($revWA - 0.1) * 2.0));
			$sideEased = 1.0 - pow(1.0 - $sideProgress, 3);
			$gl->glPushMatrix();
			$gl->glTranslatef(
				($sideEased - 1.0) * self::SIDEBAR_W,
				self::TITLEBAR_H,
				0,
			);
			$this->globalAlpha = $sideEased;
			$this->renderSidebar();
			$gl->glPopMatrix();

			// Content Area: Fade + Scale Pop
			$contProgress = max(0.0, min(1.0, ($revWA - 0.2) * 2.0));
			$contEased = 1.0 - pow(1.0 - $contProgress, 3);
			$contScale = 0.98 + 0.02 * $contEased;

			$gl->glPushMatrix();
			$cx = self::SIDEBAR_W + ($this->width - self::SIDEBAR_W) / 2;
			$cy = self::TITLEBAR_H + ($this->height - self::TITLEBAR_H) / 2;
			$gl->glTranslatef($cx, $cy, 0);
			$gl->glScalef($contScale, $contScale, 1.0);
			$gl->glTranslatef(-$cx, -$cy, 0);

			$gl->glPushMatrix();
			$gl->glTranslatef(self::SIDEBAR_W, self::TITLEBAR_H, 0);
			$this->globalAlpha = $contEased * $this->pageAnim;
			$gl->glTranslatef(0, (1.0 - $this->pageAnim) * 10, 0);

			switch ($this->currentPage) {
				case self::PAGE_HOME:
					$this->renderHomePage();
					break;
				case self::PAGE_FOXYCLIENT:
					$this->renderFoxyClientPage();
					break;
				case self::PAGE_LOGIN:
					$this->renderLoginPage();
					break;
				case self::PAGE_VERSIONS:
					$this->renderVersionsPage();
					break;
				case self::PAGE_MODS:
					$this->renderModsPage();
					break;
				case self::PAGE_ACCOUNTS:
					$this->renderAccountsPage();
					break;
				case self::PAGE_PROPERTIES:
					$this->renderPropertiesPage();
					break;
			}

			// Footer: Slide from bottom
			$footProgress = max(0.0, min(1.0, ($revWA - 0.4) * 2.5));
			$footEased = 1.0 - pow(1.0 - $footProgress, 3);
			$gl->glPushMatrix();
			$gl->glTranslatef(0, (1.0 - $footEased) * 40, 0);
			$this->globalAlpha = $footEased;
			$this->renderFooter();
			$gl->glPopMatrix();

			$gl->glPopMatrix(); // End content translate
			$gl->glPopMatrix(); // End content scale

			// Title Bar: Slide from top
			$titleProgress = max(0.0, min(1.0, ($revWA - 0.2) * 2.0));
			$titleEased = 1.0 - pow(1.0 - $titleProgress, 3);
			$gl->glPushMatrix();
			$gl->glTranslatef(0, ($titleEased - 1.0) * self::TITLEBAR_H, 0);
			$this->globalAlpha = $titleEased;
			$this->renderTitleBar();
			$gl->glPopMatrix();

			// Modals
			$this->globalAlpha = $revWA > 0.9 ? 1.0 : 0.0;
			if ($this->javaModalOpen) {
				$this->renderJavaModal();
			}
			if ($this->bgModalOpen) {
				$this->renderBgModal();
			}
		}

		$this->globalAlpha = 1.0;
	}

	private function renderSubTabs($tabs, $activeIdx, $hoverIdxBase)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$y = self::HEADER_H;

		// Tab bar background
		$this->drawRect(0, $y, $cw, self::TAB_H, $this->colors["tab_bg"]);
		$this->drawRect(
			0,
			$y + self::TAB_H - 1,
			$cw,
			1,
			$this->colors["divider"],
		);

		$tabX = self::PAD;
		foreach ($tabs as $i => $name) {
			$tw = $this->getTextWidth($name, 1000) + 32;
			$isActive = $i === $activeIdx;
			$isHover = $this->subTabHoverIdx === $hoverIdxBase + $i;

			if ($isActive) {
				$this->drawRect(
					$tabX,
					$y,
					$tw,
					self::TAB_H,
					$this->colors["tab_active"],
				);
				$this->drawRect(
					$tabX,
					$y + self::TAB_H - 3,
					$tw,
					3,
					$this->colors["primary"],
				);
				$this->renderText(
					$name,
					$tabX + 16,
					$y + 26,
					$this->colors["text"],
					1000,
				);
			} else {
				if ($isHover) {
					$this->drawRect(
						$tabX,
						$y,
						$tw,
						self::TAB_H,
						$this->colors["subtab"],
					);
				}
				$this->renderText(
					$name,
					$tabX + 16,
					$y + 26,
					$this->colors["text_dim"],
					1000,
				);
			}
			$tabX += $tw + 8;
		}
	}

	private function renderSearchBar($x, $y, $w, $h, &$query, $isFocused)
	{
		$bgColor = $isFocused
			? $this->colors["input_bg_active"]
			: $this->colors["input_bg"];
		$this->drawRect($x, $y, $w, $h, $bgColor);
		$borderColor = $isFocused
			? $this->colors["primary"]
			: $this->colors["divider"];
		$this->drawRect($x, $y + $h - 2, $w, 2, $borderColor);

		// Search icon placeholder
		$this->renderText(
			"🔍",
			$x + 10,
			$y + 26,
			$this->colors["text_dim"],
			3000,
		);

		$displayText = $query . ($isFocused ? "_" : "");
		if (empty($query) && !$isFocused) {
			$this->renderText(
				"Search Modrinth...",
				$x + 35,
				$y + 26,
				$this->colors["text_dim"],
				1000,
			);
		} else {
			$this->renderText(
				$displayText,
				$x + 35,
				$y + 26,
				$this->colors["text"],
				1000,
			);
		}
	}

	private function renderSidebar()
	{
		$sw = self::SIDEBAR_W;
		// Sidebar bg
		$c1 = $this->colors["sidebar_bg1"];
		$c2 = $this->colors["sidebar_bg2"];
		if ($this->bgTex) {
			$c1 = [$c1[0], $c1[1], $c1[2], 0.8];
			$c2 = [$c2[0], $c2[1], $c2[2], 0.8];
		}
		$this->drawGradientRect(
			0,
			0,
			$sw,
			$this->height - self::TITLEBAR_H,
			$c1,
			$c2,
		);
		// Divider
		$this->drawRect(
			$sw - 1,
			0,
			1,
			$this->height - self::TITLEBAR_H,
			$this->colors["divider"],
		);

		// Logo area
		$this->drawTexture($this->logoTex, 20, 20, 48, 48); // 48x48 icon
		$this->renderText("FoxyClient", 75, 52, $this->colors["primary"], 2000);

		// Sidebar Items
		$itemH = 50;
		$y = 100;

		$sidebarIcons = [
			self::PAGE_HOME => "⌂",
			self::PAGE_FOXYCLIENT => "★",
			self::PAGE_ACCOUNTS => "☻",
			self::PAGE_MODS => "☰",
			self::PAGE_VERSIONS => "↕",
			self::PAGE_PROPERTIES => "⚙",
		];

		$hasActiveTab = false;
		foreach ($this->sidebarItems as $item) {
			if ($this->currentPage === $item["id"]) {
				$hasActiveTab = true;
				break;
			}
		}

		if ($hasActiveTab) {
			$this->drawRect(
				5,
				$this->sidebarIndicatorY,
				$sw - 10,
				$itemH,
				$this->colors["sidebar_active"],
			);
			$this->drawRect(
				0,
				$this->sidebarIndicatorY,
				4,
				$itemH,
				$this->colors["primary"],
			);
		}

		// Hover Highlight
		if ($this->sidebarHoverAlpha > 0.001) {
			$hC = $this->colors["sidebar_hover"];
			$this->drawRect(5, $this->sidebarHoverY, $sw - 10, $itemH, [
				$hC[0],
				$hC[1],
				$hC[2],
				$this->sidebarHoverAlpha,
			]);
		}

		foreach ($this->sidebarItems as $i => $item) {
			$isActive = $this->currentPage === $item["id"];

			$color = $isActive
				? $this->colors["text"]
				: $this->colors["text_dim"];

			// Icon
			$icon = $sidebarIcons[$item["id"]] ?? "";
			if ($icon !== "") {
				$iconColor = $isActive ? $this->colors["primary"] : $this->colors["text_dim"];
				$this->renderText($icon, 18, $y + 32, $iconColor, 1000);
			}

			$this->renderText($item["name"], 40, $y + 32, $color, 1000);
			$y += $itemH + 5;
		}

		// Profile area at bottom
		$sidebarH = $this->height - self::TITLEBAR_H;
		$profileY = $sidebarH - 80;

		$this->drawRect(10, $profileY, $sw - 20, 1, $this->colors["divider"]);

		// Glassmorphic profile card
		$profileBg = $this->sidebarHover === 99
			? [0.12, 0.14, 0.18, 0.5]
			: [0.08, 0.09, 0.11, 0.3];
		$this->drawRect(8, $profileY + 6, $sw - 16, 50, $profileBg);
		$this->drawRect(8, $profileY + 6, $sw - 16, 1, [1, 1, 1, 0.04]);

		// Active Account Link
		$accColor =
			$this->sidebarHover === 99
				? $this->colors["primary"]
				: $this->colors["text"];
		$dispName = $this->accountName ?: "Not Logged In";

		if ($this->isLoggedIn && $this->activeAccount) {
			$accData = $this->accounts[$this->activeAccount] ?? [];
			$type = $accData["Type"] ?? self::ACC_OFFLINE;
			$tex = null;
			if ($type === self::ACC_MICROSOFT) {
				$tex = $this->mojangTex;
			} elseif ($type === self::ACC_ELYBY) {
				$tex = $this->elybyTex;
			} elseif ($type === self::ACC_FOXY) {
				$tex = $this->logoTex;
			}

			if ($tex) {
				$this->drawTexture($tex, 25, $profileY + 22, 20, 20); // Aligned with text alignment (X=25)
				$this->renderText(
					$dispName,
					55,
					$profileY + 38,
					$accColor,
					1000,
				);
			} else {
				$this->renderText(
					$dispName,
					25,
					$profileY + 38,
					$accColor,
					1000,
				);
			}
		} else {
			$this->renderText($dispName, 25, $profileY + 38, $accColor, 1000);
		}

		// Version badge
		$verText = "v" . self::VERSION;
		$verW = $this->getTextWidth($verText, 3000) + 10;
		$this->drawRect($sw - $verW - 12, $profileY + 38, $verW, 16, [0, 0, 0, 0.2]);
		$this->renderText($verText, $sw - $verW - 7, $profileY + 49, $this->colors["text_dim"], 3000);
	}

	// ─── HOME PAGE ENGINE ───

	// Returns bounds of Account Dropdown (x, y, w, h)
	private function getHomeAccDropdownRect()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$w = 400;
		$h = 40;
		$x = ($cw - $w) / 2;
		$y = 150 + 80; // Offset for logo
		return [$x, $y, $w, $h];
	}

	private function getHomeUpdateBadgeRect()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$w = 180;
		$h = 30;
		$x = ($cw - $w) / 2;
		$y = 185;
		return [$x, $y, $w, $h];
	}

	// Returns bounds of Version Dropdown (x, y, w, h)
	private function getHomeVerDropdownRect()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$w = 400;
		$h = 40;
		$x = ($cw - $w) / 2;
		$y = 250 + 80; // Offset for logo
		return [$x, $y, $w, $h];
	}

	// Returns bounds of Launch Button (x, y, w, h)
	private function getHomeLaunchRect()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$usableH = $this->height - self::TITLEBAR_H;
		$w = 300;
		$h = 60;
		$x = ($cw - $w) / 2;
		$y = $usableH - 120;
		return [$x, $y, $w, $h];
	}

	private function getHomeVersions()
	{
		$showMod = (bool) ($this->settings["show_modified_versions"] ?? false);
		$baseGroups = [];
		foreach ($this->versions as $v) {
			if (!isset($v["id"])) {
				continue;
			}
			$id = $v["id"];
			$type = $v["type"] ?? "";

			if ($type === "release") {
				if (!isset($baseGroups[$id])) {
					$baseGroups[$id] = [];
				}
				$baseGroups[$id]["release"] = [
					"id" => $id,
					"type" => "release",
					"label" => "Release " . $id,
				];
			} elseif ($type === "modified" && $showMod) {
				// First find the base version (usually X.Y.Z at end or middle)
				$baseVer = "unknown";
				if (preg_match('/(\d+\.\d+(?:\.\d+)?)$/', $id, $matches)) {
					$baseVer = $matches[1];
				} elseif (
					preg_match(
						"/(?:-|\s)(\d+\.\d+(?:\.\d+)?)(?:-|\s)/",
						$id,
						$matches,
					)
				) {
					$baseVer = $matches[1];
				}

				// Extract the pure loader name (Fabric, Forge, NeoForge, Quilt, OptiFine)
				$loaderRaw = "mod";
				if (
					preg_match(
						"/^(fabric|forge|neoforge|quilt|optifine)/i",
						$id,
						$matches,
					)
				) {
					$loaderRaw = strtolower($matches[1]);
				} else {
					// fallback piece
					$parts = preg_split("/[- \_]/", $id);
					$loaderRaw = strtolower($parts[0] ?? "mod");
				}

				$loaderName = "Mod";
				if ($loaderRaw === "neoforge") {
					$loaderName = "NeoForge";
				} elseif ($loaderRaw === "optifine") {
					$loaderName = "OptiFine";
				} else {
					$loaderName = ucfirst($loaderRaw);
				}

				if ($baseVer !== "unknown") {
					if (!isset($baseGroups[$baseVer])) {
						$baseGroups[$baseVer] = [];
					}
					$baseGroups[$baseVer][$loaderRaw] = [
						"id" => $id,
						"type" => "modified",
						"label" => $loaderName . " " . $baseVer,
					];
				}
			}
		}

		// Sort the base version groups descending
		uksort($baseGroups, function ($a, $b) {
			return version_compare($b, $a);
		});

		$out = [];
		// Order to alternate within each version block
		$order = ["release", "fabric", "forge", "neoforge", "quilt"];
		foreach ($baseGroups as $baseVer => $group) {
			foreach ($order as $key) {
				if (isset($group[$key])) {
					$out[] = $group[$key];
				}
			}
			// Grab any leftovers not in $order
			foreach ($group as $key => $item) {
				if (!in_array($key, $order)) {
					$out[] = $item;
				}
			}
		}
		return $out;
	}

	private function handleHomePageClick($cx, $cy)
	{
		[$ax, $ay, $aw, $ah] = $this->getHomeAccDropdownRect();
		[$vx, $vy, $vw, $vh] = $this->getHomeVerDropdownRect();
		[$lx, $ly, $lw, $lh] = $this->getHomeLaunchRect();
		[$ux, $uy, $uw, $uh] = $this->getHomeUpdateBadgeRect();

		$y = $cy; // Map $cy to local $y for existing logic compatibility if preferred, but I'll just replace $y in comparisons.

		// Check dropdowns first - items
		if ($this->homeAccDropdownOpen) {
			if (
				$cx >= $ax &&
				$cx <= $ax + $aw &&
				$y >= $ay + $ah &&
				$y <= $ay + $ah + (count($this->accounts) + 1) * 40
			) {
				$localY = $y - ($ay + $ah);
				$idx = floor($localY / 40);
				$uuids = array_keys($this->accounts);
				
				if ($idx >= 0 && $idx < count($this->accounts)) {
					if (isset($uuids[$idx])) {
						$this->selectAccount($uuids[$idx]);
					}
				} elseif ($idx == count($this->accounts)) {
					$this->switchPage(self::PAGE_LOGIN);
					$this->loginStep = 0;
					$this->loginInput = "";
				}
				$this->homeAccDropdownOpen = false;
				return;
			}
		}

		// Update Badge Hit
		if ($this->hasUiUpdate) {
			[$ux, $uy, $uw, $uh] = $this->getHomeUpdateBadgeRect();
			if ($cx >= $ux && $cx <= $ux + $uw && $cy >= $uy && $cy <= $uy + $uh) {
				$this->performSelfUpdate();
				return;
			}
		}

		if ($this->homeVerDropdownOpen) {
			$filtered = $this->getHomeVersions();
			$ddH = min(200, count($filtered) * 40);
			// Scrollbar hit detection
			$contentH = count($filtered) * 40;
			if ($contentH > $ddH) {
				$scrollH = max(20, ($ddH / $contentH) * $ddH);
				$thumbY =
					$vy +
					$vh +
					($this->homeVerScrollOffset / ($contentH - $ddH)) *
						($ddH - $scrollH);
				if (
					$cx >= $vx + $vw - 12 &&
					$cx <= $vx + $vw &&
					$cy >= $thumbY &&
					$cy <= $thumbY + $scrollH
				) {
					$this->isDraggingScroll = true;
					$this->dragType = "home_dropdown";
					$this->dragStartY = $this->mouseY;
					$this->dragStartOffset = $this->homeVerScrollOffset;
					return;
				}
			}

			if (
				$cx >= $vx &&
				$cx <= $vx + $vw &&
				$y >= $vy + $vh &&
				$y <= $vy + $vh + $ddH
			) {
				$localY = $y - ($vy + $vh) + $this->homeVerScrollOffset;
				$idx = (int) floor($localY / 40);
				if (isset($filtered[$idx])) {
					$vId = $filtered[$idx]["id"];
					$this->selectedVersion = $vId;
					$this->config["minecraft_version"] = $this->selectedVersion;
					$this->saveConfig();

					// Auto-download removed (User requested manual download via button)
				}
				$this->homeVerDropdownOpen = false;
				return;
			}
		}

		// Checking clicks on root elements
		if ($cx >= $ax && $cx <= $ax + $aw && $y >= $ay && $y <= $ay + $ah) {
			$this->homeAccDropdownOpen = !$this->homeAccDropdownOpen;
			$this->homeVerDropdownOpen = false; // close the other
		} elseif (
			$cx >= $vx &&
			$cx <= $vx + $vw &&
			$y >= $vy &&
			$y <= $vy + $vh
		) {
			$this->homeVerDropdownOpen = !$this->homeVerDropdownOpen;
			$this->homeAccDropdownOpen = false; // close the other
		} elseif (
			$cx >= $lx &&
			$cx <= $lx + $lw &&
			$y >= $ly &&
			$y <= $ly + $lh
		) {
			if (
				$this->isLoggedIn &&
				!$this->isLaunching &&
				$this->assetMessage !== "GAME RUNNING" 
			) {
				$jarPath =
					$this->settings["game_dir"] .
					DIRECTORY_SEPARATOR .
					"versions" .
					DIRECTORY_SEPARATOR .
					$this->selectedVersion .
					DIRECTORY_SEPARATOR .
					$this->selectedVersion .
					".jar";
				if (file_exists($jarPath)) {
					$this->launchGame();
				} else {
					$this->triggerVersionDownload($this->selectedVersion, true);
				}
			}
			$this->homeAccDropdownOpen = false;
			$this->homeVerDropdownOpen = false;
		} else {
			// Modpack checkbox click — only for Fabric versions
			$isFabricVersion =
				stripos($this->selectedVersion, "fabric") !== false;
			if ($isFabricVersion) {
				$cbX = ($cw = $this->width - self::SIDEBAR_W)
					? ($this->width - self::SIDEBAR_W - 250) / 2
					: 0;
				$cbY = $vy + $vh + 20;
				if (
					$cx >= $cbX &&
					$cx <= $cbX + 250 &&
					$y >= $cbY &&
					$y <= $cbY + 22
				) {
					$this->settings["enable_modpack"] = !(
						$this->settings["enable_modpack"] ?? false
					);
					$this->saveSettings();

					if ($this->settings["enable_modpack"]) {
						$this->startUpdate(); // Trigger sync when enabled
					}
					return;
				}
			}
			// click outside closes both
			$this->homeAccDropdownOpen = false;
			$this->homeVerDropdownOpen = false;
		}
	}

	private function computeHomePageHover($cx, $cy)
	{
		$this->homeHoverIdx = -1; // -1 none, 0 acc_dd, 1 ver_dd, 2 launch, >=10 items
		[$ax, $ay, $aw, $ah] = $this->getHomeAccDropdownRect();
		[$vx, $vy, $vw, $vh] = $this->getHomeVerDropdownRect();
		[$lx, $ly, $lw, $lh] = $this->getHomeLaunchRect();
		[$ux, $uy, $uw, $uh] = $this->getHomeUpdateBadgeRect();

		$y = $cy;

		// Check open dropdown items first (they overlap everything below)
		if ($this->homeAccDropdownOpen) {
			if (
				$cx >= $ax &&
				$cx <= $ax + $aw &&
				$y >= $ay + $ah &&
				$y <= $ay + $ah + (count($this->accounts) + 1) * 40
			) {
				$localY = $y - ($ay + $ah);
				$this->homeHoverIdx = 10 + floor($localY / 40);
				return;
			}
		}

		if ($this->homeVerDropdownOpen) {
			$filtered = $this->getHomeVersions();
			$ddH = min(200, count($filtered) * 40);
			if (
				$cx >= $vx &&
				$cx <= $vx + $vw &&
				$y >= $vy + $vh &&
				$y <= $vy + $vh + $ddH
			) {
				$localY = $y - ($vy + $vh) + $this->homeVerScrollOffset;
				$this->homeHoverIdx = 1000 + (int) floor($localY / 40);
				return;
			}
		}

		// Base elements
		if ($cx >= $ax && $cx <= $ax + $aw && $y >= $ay && $y <= $ay + $ah) {
			$this->homeHoverIdx = 0;
		} elseif (
			$cx >= $vx &&
			$cx <= $vx + $vw &&
			$y >= $vy &&
			$y <= $vy + $vh
		) {
			$this->homeHoverIdx = 1;
		} elseif (
			$cx >= $lx &&
			$cx <= $lx + $lw &&
			$y >= $ly &&
			$y <= $ly + $lh
		) {
			$this->homeHoverIdx = 2;
		} elseif (
			$this->hasUiUpdate &&
			$cx >= $ux && $cx <= $ux + $uw && $y >= $uy && $y <= $uy + $uh
		) {
			$this->homeHoverIdx = 4;
		} else {
			// Modpack checkbox hover — only for Fabric versions
			$isFabricVersion =
				stripos($this->selectedVersion, "fabric") !== false;
			if ($isFabricVersion) {
				$cw = $this->width - self::SIDEBAR_W;
				$cbX = ($cw - 250) / 2;
				$cbY = $vy + $vh + 20;
				if (
					$cx >= $cbX &&
					$cx <= $cbX + 250 &&
					$y >= $cbY &&
					$y <= $cbY + 22
				) {
					$this->homeHoverIdx = 3;
				}
			}
		}
	}

	private function renderHomePage()
	{
		$cw = $this->width - self::SIDEBAR_W;

		// Header with Logo
		$this->drawTexture($this->logoTex, ($cw - 80) / 2, 20, 80, 80);
		$this->renderText(
			"FOXY CLIENT",
			($cw - 154) / 2,
			125,
			$this->colors["primary"],
			2000,
		);
		$this->renderText(
			"Ready to play.",
			($cw - 126) / 2,
			155,
			$this->colors["text_dim"],
			$this->hasUiUpdate ? 0 : 1000, // Hide if update available
		);

		// Update Badge
		if ($this->hasUiUpdate) {
			[$ux, $uy, $uw, $uh] = $this->getHomeUpdateBadgeRect();
			$uHover = $this->homeHoverIdx === 4;
			$this->drawStyledButton($ux, $uy, $uw, $uh, "UPDATE AVAILABLE", $uHover, "success");
		}

		// List of rects (sync with handling)
		[$ax, $ay, $aw, $ah] = $this->getHomeAccDropdownRect();
		[$vx, $vy, $vw, $vh] = $this->getHomeVerDropdownRect();
		[$lx, $ly, $lw, $lh] = $this->getHomeLaunchRect();

		// Account Section
		$accLabel = "ACCOUNT";
		$this->renderText(
			$accLabel,
			$ax - $this->getTextWidth($accLabel, 3000) - 10,
			$ay + 18,
			$this->colors["text_dim"],
			3000,
		);
		$dispName = $this->accountName ?: "Not Logged In";
		$isAccHover = $this->homeHoverIdx === 0;
		$this->drawDropdownSelector($ax, $ay, $aw, $ah, $dispName, $this->homeAccDropdownOpen, $isAccHover);

		// Account icon overlay
		if ($this->isLoggedIn && $this->activeAccount) {
			$accData = $this->accounts[$this->activeAccount] ?? [];
			$type = $accData["Type"] ?? self::ACC_OFFLINE;
			$tex = null;
			if ($type === self::ACC_MICROSOFT) {
				$tex = $this->mojangTex;
			} elseif ($type === self::ACC_ELYBY) {
				$tex = $this->elybyTex;
			} elseif ($type === self::ACC_FOXY) {
				$tex = $this->logoTex;
			}
			if ($tex) {
				$this->drawTexture($tex, $ax + $aw - 50, $ay + 8, 24, 24);
			}
		}


		// Version Section
		$verLabel = "VERSION";
		$this->renderText(
			$verLabel,
			$vx - $this->getTextWidth($verLabel, 3000) - 10,
			$vy + 18,
			$this->colors["text_dim"],
			3000,
		);
		$isInstalled = false;
		if ($this->selectedVersion) {
			$jarPath =
				$this->settings["game_dir"] .
				DIRECTORY_SEPARATOR .
				"versions" .
				DIRECTORY_SEPARATOR .
				$this->selectedVersion .
				DIRECTORY_SEPARATOR .
				$this->selectedVersion .
				".jar";
			$isInstalled = file_exists($jarPath);
		}
		$dispVer = $this->selectedVersion ?: "None Selected";
		if ($this->selectedVersion) {
			$isRelease = true;
			foreach ($this->versions as $v) {
				if (
					isset($v["id"]) &&
					$v["id"] === $this->selectedVersion &&
					($v["type"] ?? "") !== "release"
				) {
					$isRelease = false;
					break;
				}
			}
			if ($isRelease && strpos($dispVer, "Release") === false) {
				$dispVer = "Release " . $dispVer;
			}
			$dispVer .= $isInstalled ? " (Installed)" : " (Available)";
		}
		$isVerHover = $this->homeHoverIdx === 1;
		$this->drawDropdownSelector($vx, $vy, $vw, $vh, $dispVer, $this->homeVerDropdownOpen, $isVerHover);

		// Modpack Toggle — only show for Fabric versions
		$isFabricVersion = stripos($this->selectedVersion, "fabric") !== false;
		if ($isFabricVersion) {
			$cbX = ($cw - 250) / 2;
			$cbY = $vy + $vh + 20;
			$modpackEnabled =
				(bool) ($this->settings["enable_modpack"] ?? false);
			$cbHover = $this->homeHoverIdx === 3;

			$this->drawToggleSwitch($cbX, $cbY, $modpackEnabled, $cbHover);

			$mpLabelColor = $cbHover
				? $this->colors["text"]
				: $this->colors["text_dim"];
			$this->renderText(
				"Enable FoxyClient Optimize",
				$cbX + 54,
				$cbY + 16,
				$mpLabelColor,
				1000,
			);
		}

		// Launch Button
		$canLaunch = $this->isLoggedIn;
		$btnStyle = $canLaunch ? "success" : "secondary";
		
		if ($this->assetMessage === "GAME RUNNING") {
			$btnStyle = "danger";
		} elseif ($this->isDownloadingAssets) {
			$btnStyle = "success";
		}
		
		$isHover = $this->homeHoverIdx === 2 && $canLaunch;

		$lText = "LAUNCH";
		if ($this->assetMessage === "GAME RUNNING") {
			$lText = "GAME RUNNING";
		} elseif ($this->isDownloadingAssets) {
			$lText = "DOWNLOADING...";
		} elseif ($this->isLaunching) {
			$lText = "LAUNCHING...";
		} elseif ($this->selectedVersion) {
			$jarPath = $this->settings["game_dir"] . DIRECTORY_SEPARATOR . "versions" . DIRECTORY_SEPARATOR . $this->selectedVersion . DIRECTORY_SEPARATOR . $this->selectedVersion . ".jar";
			if (!file_exists($jarPath)) {
				$lText = "DOWNLOAD";
				$btnStyle = "primary";
			} elseif ($this->needsModpackUpdate()) {
				$lText = "SYNC & LAUNCH";
				$btnStyle = "primary";
			}
		}

		$this->drawStyledButton($lx, $ly, $lw, $lh, $lText, $isHover, $btnStyle, 2000);

		// Progress Bar on Home Page
		if ($this->isDownloadingAssets) {
			$barW = 400;
			$barH = 8;
			$barX = ($cw - $barW) / 2;
			$barY = $ly - 30;
			$this->drawRect($barX, $barY, $barW, $barH, $this->colors["card"]);
			$this->drawRect(
				$barX,
				$barY,
				$barW * $this->assetProgress,
				$barH,
				$this->colors["primary"],
			);

			$msg = $this->assetMessage ?: "DOWNLOADING VERSION...";
			$this->renderText(
				$msg,
				$barX,
				$barY + $barH + 15,
				$this->colors["primary"],
				1000,
			);
		}

		// Draw Overlays LAST
		$gl = $this->opengl32;

		if ($this->homeAccDropdownOpen || $this->homeAccDropdownAnim > 0.01) {
			$maxH = (count($this->accounts) + 1) * 40; // +1 for Logout
			$ddH = $maxH * $this->homeAccDropdownAnim;
			$dY = $ay + $ah;

			$gl->glEnable(0x0c11); // SCISSOR
			$gl->glScissor(
				self::SIDEBAR_W + $ax,
				$this->height - ($dY + self::TITLEBAR_H + $ddH),
				$aw,
				$ddH,
			);

			$this->drawRect($ax, $dY, $aw, $maxH, $this->colors["dropdown_bg"], 8);
			$this->drawRect($ax, $dY, $aw, 1, $this->colors["divider"]);

			$i = 0;
			foreach ($this->accounts as $uuid => $accData) {
				$itemY = $dY + $i * 40;
				$isHover = $this->homeHoverIdx === 10 + $i;
				if ($isHover) {
					$this->drawRect($ax + 4, $itemY + 2, $aw - 8, 36, $this->colors["dropdown_hover"], 4);
				}
				$this->drawRect($ax + 4, $itemY + 6, 2, 28, $this->colors["primary"], 1);

				$type = $accData["Type"] ?? self::ACC_OFFLINE;
				$tex = null;
				if ($type === self::ACC_MICROSOFT) {
					$tex = $this->mojangTex;
				} elseif ($type === self::ACC_ELYBY) {
					$tex = $this->elybyTex;
				} elseif ($type === self::ACC_FOXY) {
					$tex = $this->logoTex;
				}

				if ($tex) {
					$this->drawTexture($tex, $ax + 12, $itemY + 8, 24, 24);
					$name = $accData["Username"] ?? "Unknown";
					$this->renderText($name, $ax + 44, $itemY + 26, $this->colors["text"], 1000);
				} else {
					$name = $accData["Username"] ?? "Unknown";
					$this->renderText($name, $ax + 16, $itemY + 26, $this->colors["text"], 1000);
				}
				$i++;
			}

			// Add Account Item
			$itemY = $dY + $i * 40;
			$isHover = $this->homeHoverIdx === 10 + $i;
			if ($isHover) {
				$this->drawRect($ax + 4, $itemY + 2, $aw - 8, 36, $this->colors["dropdown_hover"], 4);
			}
			$this->drawRect($ax + 4, $itemY + 6, 2, 28, $this->colors["text_dim"], 1);
			$this->renderText("ADD ACCOUNT", $ax + 16, $itemY + 26, $this->colors["text_dim"], 1000);

			$gl->glDisable(0x0c11);
		}

		if ($this->homeVerDropdownOpen || $this->homeVerDropdownAnim > 0.01) {
			$filtered = $this->getHomeVersions();
			$fullDDH = min(200, count($filtered) * 40);
			$ddH = $fullDDH * $this->homeVerDropdownAnim;
			$dY = $vy + $vh;

			$this->drawRect($vx, $dY, $vw, $ddH, $this->colors["panel"]);

			$gl->glEnable(0x0c11); // SCISSOR
			$gl->glScissor(
				self::SIDEBAR_W + $vx,
				$this->height - ($dY + self::TITLEBAR_H + $ddH),
				$vw,
				$ddH,
			);
			$this->drawRect($vx, $dY, $vw, $fullDDH, $this->colors["dropdown_bg"], 8);
			$this->drawRect($vx, $dY, $vw, 1, $this->colors["divider"]);

			$itemY = $dY - $this->homeVerScrollOffset;
			foreach ($filtered as $i => $v) {
				if ($itemY + 40 > $dY && $itemY < $dY + $ddH) {
					$isHover = $this->homeHoverIdx === 1000 + $i;
					if ($isHover) {
						$this->drawRect($vx + 4, $itemY + 2, $vw - 8, 36, $this->colors["dropdown_hover"], 4);
					}
					
					$accentColor = $v["type"] === "release" ? $this->colors["primary"] : $this->colors["text_dim"];
					$this->drawRect($vx + 4, $itemY + 6, 2, 28, $accentColor, 1);
					
					$jarP = $this->settings["game_dir"] . DIRECTORY_SEPARATOR . "versions" . DIRECTORY_SEPARATOR . $v["id"] . DIRECTORY_SEPARATOR . $v["id"] . ".jar";
					$isInst = file_exists($jarP);
					$textC = $isInst ? $this->colors["text"] : $this->colors["text_dim"];
					$label = $v["id"] . ($isInst ? " (Installed)" : "");
					$this->renderText($label, $vx + 16, $itemY + 26, $textC, 1000);
				}
				$itemY += 40;
			}
			$gl->glDisable(0x0c11);

			// Scrollbar if needed
			$contentH = count($filtered) * 40;
			if ($contentH > $ddH) {
				$scrollH = max(20, ($ddH / $contentH) * $ddH);
				$scrollY =
					$dY +
					($this->homeVerScrollOffset / ($contentH - $ddH)) *
						($ddH - $scrollH);
				$this->drawRect(
					$vx + $vw - 6,
					$dY,
					6,
					$ddH,
					$this->colors["bg"],
				); // bg
				$this->drawRect(
					$vx + $vw - 5,
					$scrollY,
					4,
					$scrollH,
					$this->colors["tab_active"],
				); // handle
			}
		}
	}

	private function renderFoxyClientPage()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$gl = $this->opengl32;

		$descs = [
			"Manage built-in optimization mods",
			"Configure module keybinds",
			"Manage chat macros",
			"FoxyClient mod settings",
			"Customize skin and cape",
			"System overlay and display settings",
		];
		$desc = $descs[$this->foxySubTab] ?? $descs[0];
		$this->drawPageHeader("FOXYCLIENT CONFIGURATION", $desc);

		// Buttons at Top Right
		$installBtnW = 180;
		$installBtnH = 32;
		$installBtnX = $cw - self::PAD - $installBtnW;
		$installBtnY = 10;

		$updateBtnW = 180;
		$updateBtnH = 32;
		$updateBtnX = $installBtnX - self::PAD - $updateBtnW;
		$updateBtnY = 10;

		// Click registration for Install button
		$this->foxyInstallBtnHover =
			$this->mouseX >= self::SIDEBAR_W + $installBtnX &&
			$this->mouseX <= self::SIDEBAR_W + $installBtnX + $installBtnW &&
			$this->mouseY >= self::TITLEBAR_H + $installBtnY &&
			$this->mouseY <= self::TITLEBAR_H + $installBtnY + $installBtnH;

		// Click registration for Update button
		$this->foxyUpdateBtnHover =
			$this->mouseX >= self::SIDEBAR_W + $updateBtnX &&
			$this->mouseX <= self::SIDEBAR_W + $updateBtnX + $updateBtnW &&
			$this->mouseY >= self::TITLEBAR_H + $updateBtnY &&
			$this->mouseY <= self::TITLEBAR_H + $updateBtnY + $updateBtnH;

		// Main Action Button (Install/Installed/Update)
		$statusLabel = "INSTALL FOXYCLIENT MOD";
		$statusStyle = "success";
		
		if ($this->isInstallingFoxyMod) {
			$statusLabel = $this->foxyInstallProgress;
			$statusStyle = "secondary";
		} elseif ($this->foxyModUpdateAvailable) {
			$statusLabel = "UPDATE FOXYCLIENT MOD";
			$statusStyle = "warning";
		} elseif ($this->foxyModLocalVersion !== null) {
			$statusLabel = "FOXYCLIENT MOD INSTALLED";
			$statusStyle = "secondary";
		}

		$this->drawStyledButton($installBtnX, $installBtnY, $installBtnW, $installBtnH,
			strtoupper($statusLabel), $this->foxyInstallBtnHover, $statusStyle);

		// Update Mods button (side of install action)
		if ($this->foxySubTab === 0) {
			$this->drawStyledButton($updateBtnX, $updateBtnY, $updateBtnW, $updateBtnH,
				"UPDATE MODS", $this->foxyUpdateBtnHover);
		}

		// Sub-tabs: 6 tabs
		$tabNames = ["Modpack", "Keybinds", "Macros", "Config", "Cosmetics", "OSD"];
		$this->renderSubTabs($tabNames, $this->foxySubTab, 100);

		$y = self::HEADER_H + self::TAB_H;
		$usableH = $this->height - self::TITLEBAR_H;
		$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
		$h = $usableH - $footerH - $y;

		switch ($this->foxySubTab) {
			case 0: // Modpack
				$this->renderFoxyModpackTab($y, $h, $cw);
				break;
			case 1: // Keybinds
				$this->renderFoxyKeybindsTab($y, $h, $cw);
				break;
			case 2: // Macros
				$this->renderFoxyMacrosTab($y, $h, $cw);
				break;
			case 3: // Config
				$this->renderFoxyConfigTab($y, $h, $cw);
				break;
			case 4: // Cosmetics
				$this->renderFoxyCosmeticsTab($y, $h, $cw);
				break;
			case 5: // OSD
				$y = 130;
				$y = $this->renderSettingsToggle($y, "Display CPU Usage", 0, "overlay_cpu");
				$y = $this->renderSettingsToggle($y, "Display GPU Usage", 1, "overlay_gpu");
				$y = $this->renderSettingsToggle($y, "Display RAM Usage", 2, "overlay_ram");
				$y = $this->renderSettingsToggle($y, "Display VRAM Usage", 3, "overlay_vram");
				break;
		}

		// Poll FoxyClientMod install progress
		if ($this->isInstallingFoxyMod && $this->foxyModInstallChannel) {
			try {
				$msg = $this->foxyModInstallChannel->recv();
				if ($msg) {
					if (str_starts_with($msg, "DONE:")) {
						$this->foxyInstallProgress = substr($msg, 5);
						$this->isInstallingFoxyMod = false;
						$this->log("FoxyClientMod: " . $this->foxyInstallProgress);
					} elseif (str_starts_with($msg, "ERROR:")) {
						$this->foxyInstallProgress = substr($msg, 6);
						$this->isInstallingFoxyMod = false;
						$this->log("FoxyClientMod install error: " . $this->foxyInstallProgress);
					} else {
						$this->foxyInstallProgress = $msg;
					}
					$this->needsRedraw = true;
				}
			} catch (\parallel\Channel\Error\Closed $e) {
				$this->isInstallingFoxyMod = false;
			} catch (\Throwable $e) {
				// Channel empty, no message yet
			}
		}
	}

	private function renderFoxyModpackTab($y, $h, $cw)
	{
		$gl = $this->opengl32;
		$oldActive = $this->activeTab;
		$this->activeTab = 0;

		$listTop = $y;
		$listH = $h;
		$itemY = $listTop + 10 - $this->scrollOffset;
		
		$gl->glEnable(0x0c11); // SCISSOR
		$gl->glScissor(
			self::SIDEBAR_W,
			$this->height - ($listTop + self::TITLEBAR_H + $listH),
			$cw,
			$listH,
		);

		foreach ($this->tabs[0]["mods"] as $i => $mod) {
			if ($itemY + self::CARD_H > $y && $itemY < $y + $h) {
				$isHover =
					$this->hoverModIndex === $i &&
					$this->currentPage === self::PAGE_FOXYCLIENT;
				$this->drawModCard($mod, $itemY, $isHover);
			}
			$itemY += self::CARD_H + self::CARD_GAP;
		}

		$gl->glDisable(0x0c11);

		$this->renderScrollbar($y, $h);
		$this->activeTab = $oldActive;
	}

	private function renderFoxyKeybindsTab($y, $h, $cw)
	{
		$gl = $this->opengl32;
		$modules = $this->foxyKeybindData;
		if (empty($modules)) {
			$this->renderText(
				"No keybind data found. Make sure FoxyClient mod is installed.",
				self::PAD, $y + 40, $this->colors["text_dim"], 1000,
			);
			return;
		}

		// Search Bar
		$searchW = 250;
		$searchX = $cw - self::PAD - $searchW;
		$this->renderSearchBar(
			$searchX,
			$y + 1,
			$searchW,
			32,
			$this->foxyKeybindSearchQuery,
			$this->foxyKeybindSearchFocus,
			"Search modules..."
		);

		// Column headers
		$headerY = $y + 38;
		$this->drawRect(0, $headerY, $cw, 30, $this->colors["card"]);
		$this->renderText("MODULE", self::PAD + 10, $headerY + 20, $this->colors["text_dim"], 3000);
		$this->renderText("KEYBIND", $cw - 200, $headerY + 20, $this->colors["text_dim"], 3000);
		$this->renderText("ON/OFF", $cw - 80, $headerY + 20, $this->colors["text_dim"], 3000);

		$listTop = $y + 68;
		$listH = $h - 68;

		$allKeys = array_keys($modules);
		$filteredKeys = [];
		foreach ($allKeys as $k) {
			if ($this->foxyKeybindSearchQuery === "" || stripos($k, $this->foxyKeybindSearchQuery) !== false) {
				$filteredKeys[] = $k;
			}
		}

		$itemH = 48; // Slightly taller
		$iy = $listTop - $this->foxyKeybindScrollOffset;
		$gl->glEnable(0x0c11); // SCISSOR
		$gl->glScissor(
			self::SIDEBAR_W,
			$this->height - ($listTop + self::TITLEBAR_H + $listH),
			$cw,
			$listH,
		);

		foreach ($filteredKeys as $fidx => $moduleName) {
			$idx = array_search($moduleName, $allKeys);

			if ($iy + $itemH > $listTop && $iy < $listTop + $listH) {
				$mod = $modules[$moduleName];
				$isHover = $this->foxyKeybindHoverIdx === $fidx;
				$isEnabled = $mod["enabled"] ?? false;
				$keyCode = $mod["keybind"] ?? -1;
				$isEditing = $this->foxyKeybindEditIdx === $fidx && $this->foxyKeybindListenMode;

				// Card background (Glassy)
				$cardBg = $isHover ? $this->colors["card_hover"] : $this->colors["card"];
				$this->drawRect(self::PAD, $iy + 2, $cw - self::PAD * 2, $itemH - 4, $cardBg, 6);
				
				// Decorative accent bar
				if ($isEnabled) {
					$this->drawRect(self::PAD, $iy + 6, 3, $itemH - 12, $this->colors["primary"], 2);
				}

				// Module Name
				$nameColor = $isEnabled ? $this->colors["text"] : $this->colors["text_dim"];
				$this->renderText($moduleName, self::PAD + 16, $iy + 31, $nameColor, 1000);

				// Keybind interaction area
				$keyW = 120;
				$keyX = $cw - self::PAD - $keyW - 80;
				$keyText = $isEditing ? "PRESS..." : "[" . $this->getGlfwKeyName($keyCode) . "]";
				$keyColor = $isEditing ? $this->colors["warning"] : ($isEnabled ? $this->colors["accent"] : $this->colors["text_dim"]);
				
				$this->renderText($keyText, $keyX, $iy + 31, $keyColor, 1000);

				// Toggle Switch
				$swW = 38;
				$swH = 18;
				$swX = $cw - self::PAD - $swW - 12;
				$swY = $iy + ($itemH - $swH) / 2;
				$swBg = $isEnabled ? $this->colors["primary"] : $this->colors["check_off"];
				$this->drawRect($swX, $swY, $swW, $swH, $swBg, 9); // Fully rounded
				
				$knobS = 14;
				$knobX = $isEnabled ? $swX + $swW - $knobS - 2 : $swX + 2;
				$this->drawRect($knobX, $swY + 2, $knobS, $knobS, [1, 1, 1, 0.9], 7);
			}
			$iy += $itemH;
		}

		$gl->glDisable(0x0c11);



		// Scrollbar
		$contentH = count($filteredKeys) * $itemH;
		if ($contentH > $listH) {
			$thumbH = max(20, ($listH / $contentH) * $listH);
			$scrollRatio = $this->foxyKeybindScrollOffset / max(1, $contentH - $listH);
			$thumbY = $listTop + ($listH - $thumbH) * $scrollRatio;
			$this->drawRect($cw - 6, $listTop, 6, $listH, $this->colors["bg"]);
			$this->drawRect($cw - 5, $thumbY, 4, $thumbH, $this->colors["tab_active"]);
		}
	}

	private function renderFoxyMacrosTab($y, $h, $cw)
	{
		$gl = $this->opengl32;
		$macros = $this->foxyMacroData;

		// Header
		$headerY = $y + 5;
		$this->drawRect(0, $headerY, $cw, 30, $this->colors["card"]);
		$this->renderText("KEY", self::PAD + 10, $headerY + 20, $this->colors["text_dim"], 3000);
		$this->renderText("COMMAND", self::PAD + 120, $headerY + 20, $this->colors["text_dim"], 3000);

		$listTop = $y + 38;
		$listH = $h - 80; // Leave room for Add button

		$keys = array_keys($macros);
		$itemH = 44;
		$iy = $listTop - $this->foxyMacroScrollOffset;
		$gl->glEnable(0x0c11); // SCISSOR
		$gl->glScissor(
			self::SIDEBAR_W,
			$this->height - ($listTop + self::TITLEBAR_H + $listH),
			$cw,
			$listH,
		);

		foreach ($keys as $idx => $keyCode) {
			if ($iy + $itemH > $listTop && $iy < $listTop + $listH) {
				$command = $macros[$keyCode];
				$isHover = $this->foxyMacroHoverIdx === $idx;

				// Row background
				$bg = $isHover ? $this->colors["card_hover"] : (($idx % 2 === 0) ? $this->colors["card"] : $this->colors["panel"]);
				$this->drawRect(self::PAD, $iy, $cw - self::PAD * 2, $itemH - 2, $bg);

				// Key badge
				$isEditingMacro = $this->foxyMacroEditIdx === $idx && $this->foxyMacroListenMode;
				$keyName = $isEditingMacro ? "PRESS..." : $this->getGlfwKeyName((int)$keyCode);
				$badgeW = max(60, $this->getTextWidth($keyName, 1000) + 20);
				$badgeBg = $isEditingMacro ? $this->colors["accent"] : $this->colors["primary"];
				$this->drawRect(self::PAD + 8, $iy + 8, $badgeW, 26, $badgeBg);
				$this->renderText($keyName, self::PAD + 18, $iy + 28, $this->colors["button_text"], 1000);

				// Command text
				if ($this->foxyMacroEditCommandIdx === $idx) {
					$cmdW = $cw - self::PAD * 2 - 180;
					$this->drawRect(self::PAD + 110, $iy + 6, $cmdW, 30, $this->colors["input_bg"], 6);
					$disp = $command . (fmod(microtime(true), 1.0) < 0.5 ? "_" : "");
					$this->renderText($disp, self::PAD + 120, $iy + 28, $this->colors["text"], 1000);
				} else {
					$this->renderText($command, self::PAD + 120, $iy + 28, $this->colors["text_dim"], 1000);
				}

				// Delete button
				$delX = $cw - self::PAD - 40;
				$delY = $iy + 6;
				$isDelHover = $isHover && $this->mouseX >= self::SIDEBAR_W + $delX;
				$delBg = $isDelHover ? $this->colors["del_btn_hover"] : [0.15, 0.15, 0.17, 1.0];
				$textColor = $isDelHover ? [1, 1, 1, 1] : $this->colors["text_dim"];
				$this->drawRect($delX, $delY, 30, 30, $delBg, 15); // Perfectly round
				$this->renderText("X", $delX + 10, $delY + 20, $textColor, 1000);
			}
			$iy += $itemH;
		}

		$gl->glDisable(0x0c11);



		// Add Macro button
		$addBtnY = $y + $h - 40;
		$addBtnW = 150;
		$addBtnH = 36;
		$addBtnHover = $this->foxyMacroHoverIdx === -2;
		$this->drawStyledButton(self::PAD, $addBtnY, $addBtnW, $addBtnH, "+ ADD MACRO", $addBtnHover);
	}

	private function renderFoxyConfigTab($y, $h, $cw)
	{
		$gl = $this->opengl32;
		$config = $this->foxyConfigData;

		if (empty($config)) {
			$this->renderText(
				"No FoxyConfig data found. Start the game with FoxyClient mod first.",
				self::PAD, $y + 40, $this->colors["text_dim"], 1000,
			);
			return;
		}

		$listTop = $y;
		$listH = $h;
		$hiddenKeys = ["skinName", "capeName", "slimModel", "customMusicName", "customFontName", "customBackgroundName", "customSkinPath", "customFontPath", "customBackgroundPath", "customMusicPath"];
		$keys = array_values(array_filter(array_keys($config), function($k) use ($hiddenKeys) {
			return !in_array($k, $hiddenKeys);
		}));
		$itemH = 70;
		$spacingY = 15;
		$colW = ($cw - self::PAD * 3) / 2;
		$iy = $listTop + 10 - $this->foxyConfigScrollOffset;

		$gl->glEnable(0x0c11); // SCISSOR
		$gl->glScissor(
			self::SIDEBAR_W,
			$this->height - ($listTop + self::TITLEBAR_H + $listH),
			$cw,
			$listH,
		);

		foreach ($keys as $idx => $key) {
			$col = $idx % 2;
			$row = floor($idx / 2);
			$ix = self::PAD + $col * ($colW + self::PAD);
			$iyCard = $listTop + 10 + $row * ($itemH + $spacingY) - $this->foxyConfigScrollOffset;

			if ($iyCard + $itemH > $listTop && $iyCard < $listTop + $listH) {
				$val = $config[$key];
				$isHover = $this->foxyConfigHoverIdx === $idx;

				// Card background
				$bg = $isHover ? $this->colors["card_hover"] : $this->colors["card"];
				$this->drawRect($ix, $iyCard, $colW, $itemH, $bg);
				
				// Left accent
				$this->drawRect($ix, $iyCard, 3, $itemH, $this->colors["primary"]);

				// Label (camelCase to readable)
				$label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
				$label = ucfirst($label);
				if ($key === "bgMusicType") $label = "Custom Music";
				$this->renderText($label, $ix + 16, $iyCard + 28, $this->colors["text"], 1000);

				// Value display
				if (is_bool($val)) {
					// Modern slider toggle
					$tw = 44;
					$th = 22;
					$tx = $ix + $colW - 16 - $tw;
					$ty = $iyCard + ($itemH - $th) / 2;
					
					$bgToggle = $val ? $this->colors["primary"] : $this->colors["input_bg"];
					$this->drawRect($tx, $ty, $tw, $th, $bgToggle);
					
					// Knob
					$kx = $val ? $tx + $tw - 18 : $tx + 2;
					$this->drawRect($kx, $ty + 2, 16, $th - 4, $this->colors["text"]);
				} else if ($key === "customFontType" || $key === "customBackgroundType" || $key === "bgMusicType") {
					$tw = 100;
					$th = 28;
					$tx = $ix + $colW - 16 - $tw;
					$ty = $iyCard + ($itemH - $th) / 2;
					
					$this->drawRect($tx, $ty, $tw, $th, $this->colors["input_bg"]);
					$this->renderText($val, $tx + 10, $ty + 20, $this->colors["text_dim"], 1000);

					if ($val === "Custom") {
						$bx = $tx - 80 - 10;
						$this->drawRect($bx, $ty, 80, $th, $this->colors["card_hover"]);
						$this->renderText("Browse", $bx + 14, $ty + 20, $this->colors["text"], 1000);

						if ($key === "bgMusicType") $targetNameKey = "customMusicName";
						else if ($key === "customFontType") $targetNameKey = "customFontName";
						else $targetNameKey = "customBackgroundName";

						$bgName = $this->foxyConfigData[$targetNameKey] ?? "";
						if ($bgName !== "") {
							$shortName = strlen($bgName) > 20 ? substr($bgName, 0, 17) . "..." : $bgName;
							$this->renderText($shortName, $bx - 140, $ty + 20, $this->colors["text_dim"], 3000);
						}
					}
				} else {
					// Text value display fallback
					$valStr = is_string($val) ? $val : json_encode($val);
					if (strlen($valStr) > 40) {
						$valStr = substr($valStr, 0, 37) . "...";
					}
					$valW = $this->getTextWidth($valStr, 3000);
					$this->drawRect($ix + $colW - 16 - $valW - 10, $iyCard + ($itemH - 28) / 2, $valW + 20, 28, $this->colors["input_bg"]);
					$this->renderText($valStr, $ix + $colW - 16 - $valW, $iyCard + 28, $this->colors["text_dim"], 3000);
				}
			}
		}

		$gl->glDisable(0x0c11);

		// Scrollbar
		$contentRows = ceil(count($keys) / 2);
		$contentH = $contentRows * ($itemH + $spacingY) + 10;
		if ($contentH > $listH) {
			$thumbH = max(20, ($listH / $contentH) * $listH);
			$scrollRatio = $this->foxyConfigScrollOffset / max(1, $contentH - $listH);
			$thumbY = $listTop + ($listH - $thumbH) * $scrollRatio;
			$this->drawRect($cw - 6, $listTop, 6, $listH, $this->colors["bg"]);
			$this->drawRect($cw - 5, $thumbY, 4, $thumbH, $this->colors["tab_active"]);
		}
	}

	private function setup3D($x, $y, $w, $h)
	{
		$gl = $this->opengl32;
		$gl->glViewport((int)$x, (int)($this->height - $y - $h), (int)$w, (int)$h);

		$gl->glMatrixMode(0x1701); // GL_PROJECTION
		$gl->glPushMatrix();
		$gl->glLoadIdentity();

		$aspect = $w / $h;
		$gl->glFrustum(-$aspect * 0.1, $aspect * 0.1, -0.1, 0.1, 0.1, 100.0);

		$gl->glMatrixMode(0x1700); // GL_MODELVIEW
		$gl->glPushMatrix();
		$gl->glLoadIdentity();

		$gl->glClear(0x00000100); // GL_DEPTH_BUFFER_BIT
		$gl->glEnable(0x0B71); // GL_DEPTH_TEST
		$gl->glDepthFunc(0x0203); // GL_LEQUAL
		// $gl->glEnable(0x0B44); // GL_CULL_FACE (Disabled just in case of wrong winding)
		// $gl->glCullFace(0x0405); // GL_BACK
	}

	private function restore2D()
	{
		$gl = $this->opengl32;
		$gl->glDisable(0x0B71); // GL_DEPTH_TEST
		$gl->glDisable(0x0B44); // GL_CULL_FACE

		$gl->glMatrixMode(0x1701); // GL_PROJECTION
		$gl->glPopMatrix();
		$gl->glMatrixMode(0x1700); // GL_MODELVIEW
		$gl->glPopMatrix();

		$gl->glViewport(0, 0, $this->width, $this->height);
	}

	private function drawBox3D($w, $h, $d, $u, $v, $texW, $texH, $scale)
	{
		$gl = $this->opengl32;
		$hw = ($w / 2.0) * $scale;
		$hh = ($h / 2.0) * $scale;
		$hd = ($d / 2.0) * $scale;

		$tx = function($x) use ($texW) { return $x / $texW; };
		$ty = function($y) use ($texH) { return $y / $texH; };

		// Front
		$gl->glBegin(0x0007); // GL_QUADS
		$gl->glNormal3f(0.0, 0.0, 1.0);
		$gl->glTexCoord2f($tx($u + $d), $ty($v + $d + $h)); $gl->glVertex3f(-$hw, -$hh, $hd);
		$gl->glTexCoord2f($tx($u + $d + $w), $ty($v + $d + $h)); $gl->glVertex3f($hw, -$hh, $hd);
		$gl->glTexCoord2f($tx($u + $d + $w), $ty($v + $d)); $gl->glVertex3f($hw, $hh, $hd);
		$gl->glTexCoord2f($tx($u + $d), $ty($v + $d)); $gl->glVertex3f(-$hw, $hh, $hd);
		$gl->glEnd();

		// Back (mirrored logic to front)
		$gl->glBegin(0x0007);
		$gl->glNormal3f(0.0, 0.0, -1.0);
		$gl->glTexCoord2f($tx($u + $d + $w + $d), $ty($v + $d + $h)); $gl->glVertex3f($hw, -$hh, -$hd);
		$gl->glTexCoord2f($tx($u + $d + $w + $d + $w), $ty($v + $d + $h)); $gl->glVertex3f(-$hw, -$hh, -$hd);
		$gl->glTexCoord2f($tx($u + $d + $w + $d + $w), $ty($v + $d)); $gl->glVertex3f(-$hw, $hh, -$hd);
		$gl->glTexCoord2f($tx($u + $d + $w + $d), $ty($v + $d)); $gl->glVertex3f($hw, $hh, -$hd);
		$gl->glEnd();

		// Top
		$gl->glBegin(0x0007);
		$gl->glNormal3f(0.0, 1.0, 0.0);
		$gl->glTexCoord2f($tx($u + $d), $ty($v + $d)); $gl->glVertex3f(-$hw, $hh, $hd);
		$gl->glTexCoord2f($tx($u + $d + $w), $ty($v + $d)); $gl->glVertex3f($hw, $hh, $hd);
		$gl->glTexCoord2f($tx($u + $d + $w), $ty($v)); $gl->glVertex3f($hw, $hh, -$hd);
		$gl->glTexCoord2f($tx($u + $d), $ty($v)); $gl->glVertex3f(-$hw, $hh, -$hd);
		$gl->glEnd();

		// Bottom
		$gl->glBegin(0x0007);
		$gl->glNormal3f(0.0, -1.0, 0.0);
		$gl->glTexCoord2f($tx($u + $d + $w), $ty($v + $d)); $gl->glVertex3f(-$hw, -$hh, $hd);
		$gl->glTexCoord2f($tx($u + $d + $w + $w), $ty($v + $d)); $gl->glVertex3f($hw, -$hh, $hd);
		$gl->glTexCoord2f($tx($u + $d + $w + $w), $ty($v)); $gl->glVertex3f($hw, -$hh, -$hd);
		$gl->glTexCoord2f($tx($u + $d + $w), $ty($v)); $gl->glVertex3f(-$hw, -$hh, -$hd);
		$gl->glEnd();

		// Right (character's right -> rendered left side of screen front)
		$gl->glBegin(0x0007);
		$gl->glNormal3f(-1.0, 0.0, 0.0);
		$gl->glTexCoord2f($tx($u), $ty($v + $d + $h)); $gl->glVertex3f(-$hw, -$hh, -$hd);
		$gl->glTexCoord2f($tx($u + $d), $ty($v + $d + $h)); $gl->glVertex3f(-$hw, -$hh, $hd);
		$gl->glTexCoord2f($tx($u + $d), $ty($v + $d)); $gl->glVertex3f(-$hw, $hh, $hd);
		$gl->glTexCoord2f($tx($u), $ty($v + $d)); $gl->glVertex3f(-$hw, $hh, -$hd);
		$gl->glEnd();

		// Left
		$gl->glBegin(0x0007);
		$gl->glNormal3f(1.0, 0.0, 0.0);
		$gl->glTexCoord2f($tx($u + $d + $w), $ty($v + $d + $h)); $gl->glVertex3f($hw, -$hh, $hd);
		$gl->glTexCoord2f($tx($u + $d + $w + $d), $ty($v + $d + $h)); $gl->glVertex3f($hw, -$hh, -$hd);
		$gl->glTexCoord2f($tx($u + $d + $w + $d), $ty($v + $d)); $gl->glVertex3f($hw, $hh, -$hd);
		$gl->glTexCoord2f($tx($u + $d + $w), $ty($v + $d)); $gl->glVertex3f($hw, $hh, $hd);
		$gl->glEnd();
	}

	private function curl_get_contents($url, $header = [])
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_USERAGENT, "FoxyClient/" . self::VERSION);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		if (!empty($header)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		}
		$data = curl_exec($ch);
		curl_close($ch);
		return $data;
	}

	private function curlDownloadAsync($id, $url, $dst = null, $header = [])
	{
		if (isset($this->httpPending[$id])) {
			return;
		}
		$this->httpPending[$id] = time();

		$this->httpQueueChannel->send([
			"type" => "generic",
			"id" => $id,
			"url" => $url,
			"path" => $dst,
			"headers" => $header
		]);
	}

	private function downloadDefaultSkin($username, $accType)
	{
		$cacheDir = self::DATA_DIR . DIRECTORY_SEPARATOR . "cache";
		if (!is_dir($cacheDir)) {
			@mkdir($cacheDir, 0777, true);
		}
		
		if (empty($username)) {
			return "";
		}

		$skinPath = $cacheDir . DIRECTORY_SEPARATOR . md5($username . $accType) . "_skin.png";
		if (file_exists($skinPath) && filemtime($skinPath) > time() - 86400 * 7) {
			return $skinPath; // Cached for 7 days
		}

		// Check if we already have a result from a background thread
		$id = "skin_" . md5($username . $accType);
		if (isset($this->httpResults[$id])) {
			$res = $this->httpResults[$id];
			unset($this->httpResults[$id]);
			if ($res["success"] && isset($res["path"])) {
				return $res["path"];
			}
			unset($this->httpPending[$id]);
		}

		// Trigger background download if not already pending
		if (!isset($this->httpPending[$id])) {
			$this->httpPending[$id] = time();
			
			$this->httpQueueChannel->send([
				"type" => "skin_resolve",
				"id" => $id,
				"username" => $username,
				"accType" => $accType,
				"path" => $skinPath
			]);
		}

		return "";
	}

	private function renderFoxyCosmeticsTab($y, $h, $cw)
	{
		$config = $this->foxyConfigData;
		$centerX = $cw / 2;

		$skinName = $config["skinName"] ?? "Default";
		$slimModel = $config["slimModel"] ?? false;
		$capeName = $config["capeName"] ?? "None";

		// Title
		$this->renderText("SKIN & CAPE", self::PAD, $y + 35, $this->colors["text"], 2000);
		$this->drawRect(self::PAD, $y + 42, 120, 2, $this->colors["primary"]);

		// Resolve actual paths for preview
		$gameDir = $this->getAbsolutePath($this->settings["game_dir"] ?? ".");
		$foxyConfigDir = $gameDir . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "foxyclient";
		
		$actualSkinPath = "";
		if ($skinName === "Custom") {
			$actualSkinPath = $foxyConfigDir . DIRECTORY_SEPARATOR . "custom_skin.png";
		} else {
			// Default -> AuthLib / Cache
			// Get current account username
			$acc = $this->accounts[$this->activeAccount] ?? null;
			if ($acc && !empty($acc["Username"])) {
				$accType = $acc["Type"] ?? self::ACC_OFFLINE;
				// Do not block UI thread long, but file_get_contents is simple enough if cached.
				// In a full async client, this should be Background. For now, try fetching it.
				$actualSkinPath = $this->downloadDefaultSkin($acc["Username"], $accType);
			}
		}

		$actualCapePath = "";
		if ($capeName === "Custom") {
			$actualCapePath = $foxyConfigDir . DIRECTORY_SEPARATOR . "custom_cape.png";
		} // Default cape could be mojang cape, but usually we just skip or load empty. None = empty.

		// Skin section
		$sy = $y + 60;

		// Skin card
		$cardW = $cw - self::PAD * 2;
		$this->drawRect(self::PAD, $sy, $cardW, 80, $this->colors["card"]);
		$this->drawRect(self::PAD, $sy, 3, 80, $this->colors["primary"]);

		$this->renderText("Skin Selection", self::PAD + 14, $sy + 24, $this->colors["text_dim"], 3000);
		
		$skinHover = $this->foxyCosmeticsHoverIdx === 1;
		$this->drawStyledButton(self::PAD + 14, $sy + 32, 100, 32, $skinName, $skinHover, "secondary");

		$modelLabel = $slimModel ? "Slim (Alex)" : "Classic";
		$modelHover = $this->foxyCosmeticsHoverIdx === 0;
		$this->drawStyledButton(self::PAD + 124, $sy + 32, 120, 32, $modelLabel, $modelHover, "secondary");

		// Browse button for custom skin
		if ($skinName === "Custom") {
			$browseHover = $this->foxyCosmeticsHoverIdx === 3;
			$this->drawStyledButton(self::PAD + 254, $sy + 32, 80, 32, "Browse", $browseHover, "secondary");
		}

		// Cape section
		$cy2 = $sy + 95;

		$this->drawRect(self::PAD, $cy2, $cardW, 80, $this->colors["card"]); // Increased height to 80 for path text
		$this->drawRect(self::PAD, $cy2, 3, 80, $this->colors["accent"]);
		$this->renderText("Cape Selection", self::PAD + 14, $cy2 + 24, $this->colors["text_dim"], 3000);
		
		$capeHover = $this->foxyCosmeticsHoverIdx === 2;
		$this->drawStyledButton(self::PAD + 14, $cy2 + 32, 100, 32, $capeName, $capeHover, "secondary");

		if ($capeName === "Custom") {
			$browseHover = $this->foxyCosmeticsHoverIdx === 4;
			$this->drawStyledButton(self::PAD + 124, $cy2 + 32, 80, 32, "Browse", $browseHover, "secondary");
		}

		// 3D Model Preview
		$previewY = $cy2 + 80;
		$previewW = min(300, $cw - self::PAD * 2);
		$previewH = min(280, $h - ($previewY - $y) - 20);
		$previewX = ($cw - $previewW) / 2;
		
		if ($previewH > 100) {
			$this->drawRect($previewX, $previewY, $previewW, $previewH, $this->colors["panel"]);
			$this->drawRect($previewX, $previewY, $previewW, 1, $this->colors["divider"]);
			$this->drawRect($previewX, $previewY + $previewH - 1, $previewW, 1, $this->colors["divider"]);
			$this->drawRect($previewX, $previewY, 1, $previewH, $this->colors["divider"]);
			$this->drawRect($previewX + $previewW - 1, $previewY, 1, $previewH, $this->colors["divider"]);

			// 1. Drag Interaction Logic
			if (!isset($this->foxyPreviewRotX)) {
				$this->foxyPreviewRotX = -10.0;
				$this->foxyPreviewRotY = -25.0;
				$this->previewLastMouseX = 0;
				$this->previewLastMouseY = 0;
				$this->previewDragging = false;
			}
			
			if (($this->user32->GetKeyState(0x01) & 0x8000) !== 0) {
				if ($this->previewDragging) {
					$dx = $this->mouseX - $this->previewLastMouseX;
					$dy = $this->mouseY - $this->previewLastMouseY;
					$this->foxyPreviewRotY += $dx * 0.5;
					$this->foxyPreviewRotX += $dy * 0.5;
					$this->foxyPreviewRotX = max(-80, min(80, $this->foxyPreviewRotX)); // limit pitch
					$this->previewLastMouseX = $this->mouseX;
					$this->previewLastMouseY = $this->mouseY;
					// Force redraw while dragging
					$this->needsRedraw = true;
				}
			} else {
				$this->previewDragging = false;
			}

			// 2. Texture Loading & Caching
			if (!isset($this->foxySkinTexPathCache) || $this->foxySkinTexPathCache !== $actualSkinPath) {
				$this->foxySkinTexPathCache = $actualSkinPath;
				if (isset($this->foxySkinTexId) && $this->foxySkinTexId > 0) {
					$gl = $this->opengl32;
					$arr = $gl->new("UINT[1]");
					$arr[0] = $this->foxySkinTexId;
					$gl->glDeleteTextures(1, FFI::addr($arr[0]));
				}
				$this->foxySkinTexId = !empty($actualSkinPath) && file_exists($actualSkinPath) ? $this->createTextureFromFile($actualSkinPath) : 0;
			}
			if (!isset($this->foxyCapeTexPathCache) || $this->foxyCapeTexPathCache !== $actualCapePath) {
				$this->foxyCapeTexPathCache = $actualCapePath;
				if (isset($this->foxyCapeTexId) && $this->foxyCapeTexId > 0) {
					$gl = $this->opengl32;
					$arr = $gl->new("UINT[1]");
					$arr[0] = $this->foxyCapeTexId;
					$gl->glDeleteTextures(1, FFI::addr($arr[0]));
				}
				$this->foxyCapeTexId = !empty($actualCapePath) && file_exists($actualCapePath) ? $this->createTextureFromFile($actualCapePath) : 0;
			}

			// 3. 3D Rendering
			if (isset($this->foxySkinTexId) && $this->foxySkinTexId > 0) {
				$this->setup3D($previewX + self::SIDEBAR_W, $previewY + self::TITLEBAR_H, $previewW, $previewH);
				$gl = $this->opengl32;
				
				// Reset vertex coloring to full bright white (so textures don't render dark)
				$gl->glColor4f(1.0, 1.0, 1.0, 1.0);

				$gl->glEnable(0x0DE1); // GL_TEXTURE_2D
				
				$gl->glBindTexture(0x0DE1, $this->foxySkinTexId);
				// Nearest neighbor filtering for Minecraft pixelated look
				$gl->glTexParameteri(0x0DE1, 0x2801, 0x2600); // GL_NEAREST
				$gl->glTexParameteri(0x0DE1, 0x2800, 0x2600);

				// Scale factor to map pixel dimensions to 3D space
				// Include zoom factor
				$scale = 0.006 * $this->foxyPreviewZoom; 
				
				// Transform stack: 
				// 3. Translate BACK into the screen
				$gl->glTranslatef(0, 0, -0.3);
				// 2. Apply Mouse Rotation
				$gl->glRotatef($this->foxyPreviewRotX, 1.0, 0.0, 0.0);
				$gl->glRotatef($this->foxyPreviewRotY, 0.0, 1.0, 0.0);
				// 1. Move skin body center (waist, basically Y=-6 in skin space) to origin (0,0,0) early so it rotates around its center
				$gl->glTranslatef(0, -6 * $scale, 0);

				// --- Player Model Parts ---
				for ($layer = 0; $layer < 2; $layer++) {
					if ($layer === 1) {
						$gl->glEnable(0x0BE2); // GL_BLEND
						$gl->glBlendFunc(0x0302, 0x0303); // GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA
					}

					$scaleL = $scale * ($layer === 1 ? 1.05 : 1.0);
					
					// Head -> L0: 0,0 | L1: 32,0
					$gl->glPushMatrix();
					$gl->glTranslatef(0, 16 * $scale, 0);
					$this->drawBox3D(8, 8, 8, $layer === 1 ? 32 : 0, 0, 64, 64, $scaleL);
					$gl->glPopMatrix();

					// Body -> L0: 16,16 | L1: 16,32
					$gl->glPushMatrix();
					$gl->glTranslatef(0, 6 * $scale, 0);
					$this->drawBox3D(8, 12, 4, 16, $layer === 1 ? 32 : 16, 64, 64, $scaleL);
					$gl->glPopMatrix();

					// Right Arm -> L0: 40,16 | L1: 40,32
					$armW = $slimModel ? 3 : 4;
					$offset = $slimModel ? 5.5 : 6;
					$gl->glPushMatrix();
					$gl->glTranslatef(-$offset * $scale, 6 * $scale, 0);
					$this->drawBox3D($armW, 12, 4, 40, $layer === 1 ? 32 : 16, 64, 64, $scaleL);
					$gl->glPopMatrix();

					// Left Arm -> L0: 32,48 | L1: 48,48
					$gl->glPushMatrix();
					$gl->glTranslatef($offset * $scale, 6 * $scale, 0);
					$this->drawBox3D($armW, 12, 4, $layer === 1 ? 48 : 32, 48, 64, 64, $scaleL);
					$gl->glPopMatrix();

					// Right Leg -> L0: 0,16 | L1: 0,32
					$gl->glPushMatrix();
					$gl->glTranslatef(-2 * $scale, -6 * $scale, 0);
					$this->drawBox3D(4, 12, 4, 0, $layer === 1 ? 32 : 16, 64, 64, $scaleL);
					$gl->glPopMatrix();

					// Left Leg -> L0: 16,48 | L1: 0,48
					$gl->glPushMatrix();
					$gl->glTranslatef(2 * $scale, -6 * $scale, 0);
					$this->drawBox3D(4, 12, 4, $layer === 1 ? 0 : 16, 48, 64, 64, $scaleL);
					$gl->glPopMatrix();

					// Removed glDisable(GL_BLEND) so it stays active for the UI
				}

				// Base Plate (Shadow/Ground)
				$gl->glDisable(0x0DE1); // No texture for shadow
				$gl->glPushMatrix();
				$gl->glTranslatef(0, -12 * $scale, 0);
				$gl->glColor4f(0.0, 0.0, 0.0, 0.3);
				$gl->glEnable(0x0BE2); // Blend for shadow
				$gl->glBlendFunc(0x0302, 0x0303);
				$gl->glBegin(0x0007); // GL_QUADS
				$gl->glVertex3f(-12 * $scale, 0, -12 * $scale);
				$gl->glVertex3f(12 * $scale, 0, -12 * $scale);
				$gl->glVertex3f(12 * $scale, 0, 12 * $scale);
				$gl->glVertex3f(-12 * $scale, 0, 12 * $scale);
				$gl->glEnd();
				// Removed glDisable(GL_BLEND) so it stays active for the UI
				$gl->glColor4f(1.0, 1.0, 1.0, 1.0); // Reset color
				$gl->glPopMatrix();
				$gl->glEnable(0x0DE1); // Re-enable for cape

				// --- Cape Rendering ---
				if (isset($this->foxyCapeTexId) && $this->foxyCapeTexId > 0) {
					$gl->glBindTexture(0x0DE1, $this->foxyCapeTexId);
					$gl->glTexParameteri(0x0DE1, 0x2801, 0x2600); // GL_NEAREST
					$gl->glTexParameteri(0x0DE1, 0x2800, 0x2600);

					$gl->glPushMatrix();
					// Pivot at the neck/shoulders back
					$gl->glTranslatef(0, 12 * $scale, -2.5 * $scale);
					$gl->glRotatef(12.0, 1.0, 0.0, 0.0); // Natural cape sloping angle
					
					// Flip the cape 180 degrees so the "Front" texture face (logo) points outwards
					$gl->glRotatef(180.0, 0.0, 1.0, 0.0);

					// Move center of cape so top edge touches pivot. 
					// Since Y is rotated 180, we offset Z positively to push local -Z face (plain inside) against the player.
					$gl->glTranslatef(0, -8 * $scale, 0.5 * $scale); 
					
					// Cape size is typically 10x16. Minecraft standard cape texture size handles things a bit weirdly,
					// but standard cape map: u=0,v=0, 64x32 mapping.
					// We'll map the front face to typical cape bounds.
					$this->drawBox3D(10, 16, 1, 0, 0, 64, 32, $scale);
					$gl->glPopMatrix();
				}

				$gl->glDisable(0x0DE1);
				$this->restore2D();
				
				// Info Text
				$this->renderText("Drag to rotate", $previewX + 10, $previewY + 10, $this->colors["text_dim"], 3000);
			} else {
				// No custom skin file found, fallback placeholder text
				$this->renderText("Waiting for skin file...", $previewX + ($previewW - 140) / 2, $previewY + $previewH / 2 - 10, $this->colors["text_dim"], 1000);
			}
		}
	}

	private function renderSettingsToggle($y, $label, $idx, $settingKey)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$val = (bool) $this->settings[$settingKey];
		$isHover = $this->foxySettingsHoverIdx === $idx;

		if ($isHover) {
			$this->drawRect(5, $y, $cw - 10, 44, [0.15, 0.15, 0.17, 0.5]);
		}

		$this->renderText(
			$label,
			self::PAD,
			$y + 26,
			$this->colors["text"],
			1000,
		);

		$tx = $cw - self::PAD - 44;
		$ty = $y + 14;

		$this->drawToggleSwitch($tx, $ty, $val, $isHover);


		return $y + 50;
	}

	private function loadFoxyKeybinds()
	{
		$path = $this->getAbsolutePath($this->settings["game_dir"]) .
			DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR .
			"foxyclient" . DIRECTORY_SEPARATOR . "foxyclient.json";
		if (file_exists($path)) {
			$data = json_decode(file_get_contents($path), true);
			if ($data) {
				$this->foxyKeybindData = $data;
				$this->log("Loaded FoxyClient keybinds: " . count($data) . " modules");
			}
		}
	}

	private function saveFoxyKeybinds()
	{
		$path = $this->getAbsolutePath($this->settings["game_dir"]) .
			DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR .
			"foxyclient" . DIRECTORY_SEPARATOR . "foxyclient.json";
		$dir = dirname($path);
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		file_put_contents($path, json_encode($this->foxyKeybindData, JSON_PRETTY_PRINT));
		$this->log("Saved FoxyClient keybinds");
	}

	private function loadFoxyMacros()
	{
		$path = $this->getAbsolutePath($this->settings["game_dir"]) .
			DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR .
			"foxyclient" . DIRECTORY_SEPARATOR . "macros.json";
		if (file_exists($path)) {
			$data = json_decode(file_get_contents($path), true);
			if ($data) {
				$this->foxyMacroData = $data;
				$this->log("Loaded FoxyClient macros: " . count($data) . " macros");
			}
		}
	}

	private function saveFoxyMacros()
	{
		$path = $this->getAbsolutePath($this->settings["game_dir"]) .
			DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR .
			"foxyclient" . DIRECTORY_SEPARATOR . "macros.json";
		$dir = dirname($path);
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		file_put_contents($path, json_encode($this->foxyMacroData, JSON_PRETTY_PRINT));
		$this->log("Saved FoxyClient macros");
	}

	private function loadFoxyConfig()
	{
		$path = $this->getAbsolutePath($this->settings["game_dir"]) .
			DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR .
			"foxyclient" . DIRECTORY_SEPARATOR . "foxyconfig.json";
		if (file_exists($path)) {
			$data = json_decode(file_get_contents($path), true);
			if ($data) {
				$this->foxyConfigData = $data;
				$this->log("Loaded FoxyConfig: " . count($data) . " entries");
			}
		}
		// Migrate and initialize custom UI fields
		if (isset($this->foxyConfigData["fontType"])) {
			$this->foxyConfigData["customFontType"] = $this->foxyConfigData["fontType"];
			unset($this->foxyConfigData["fontType"]);
		}
		if (!isset($this->foxyConfigData["customFontType"])) $this->foxyConfigData["customFontType"] = "Default";
		if (!isset($this->foxyConfigData["customBackgroundType"])) $this->foxyConfigData["customBackgroundType"] = "Default";
		if (!isset($this->foxyConfigData["customMusicPath"])) $this->foxyConfigData["customMusicPath"] = "";
		if (!isset($this->foxyConfigData["bgMusicType"])) $this->foxyConfigData["bgMusicType"] = "Default";
	}

	private function saveFoxyConfig()
	{
		$path = $this->getAbsolutePath($this->settings["game_dir"]) .
			DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR .
			"foxyclient" . DIRECTORY_SEPARATOR . "foxyconfig.json";
		$dir = dirname($path);
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		file_put_contents($path, json_encode($this->foxyConfigData, JSON_PRETTY_PRINT));
		$this->log("Saved FoxyConfig");
	}

	private function getGlfwKeyName($keyCode)
	{
		return $this->glfw_key_names[$keyCode] ?? ("KEY_" . $keyCode);
	}

	/** Map Win32 Virtual Key codes to GLFW key codes */
	private function vkToGlfw($vk)
	{
		$map = [
			0x08 => 259, // VK_BACK -> BACKSPACE
			0x09 => 258, // VK_TAB -> TAB
			0x0D => 257, // VK_RETURN -> ENTER
			0x10 => 340, // VK_SHIFT -> LSHIFT
			0x11 => 341, // VK_CONTROL -> LCTRL
			0x12 => 342, // VK_MENU (ALT) -> LALT
			0x13 => 284, // VK_PAUSE -> PAUSE
			0x14 => 280, // VK_CAPITAL -> CAPSLOCK
			0x1B => 256, // VK_ESCAPE -> ESC
			0x20 => 32,  // VK_SPACE -> SPACE
			0x21 => 266, // VK_PRIOR -> PGUP
			0x22 => 267, // VK_NEXT -> PGDN
			0x23 => 269, // VK_END -> END
			0x24 => 268, // VK_HOME -> HOME
			0x25 => 263, // VK_LEFT -> LEFT
			0x26 => 265, // VK_UP -> UP
			0x27 => 262, // VK_RIGHT -> RIGHT
			0x28 => 264, // VK_DOWN -> DOWN
			0x2D => 260, // VK_INSERT -> INSERT
			0x2E => 261, // VK_DELETE -> DELETE
			0x5B => 343, // VK_LWIN -> LSUPER
			0x5C => 347, // VK_RWIN -> RSUPER
			0x90 => 282, // VK_NUMLOCK -> NUMLOCK
			0x91 => 281, // VK_SCROLL -> SCROLLLOCK
			0xA0 => 340, // VK_LSHIFT -> LSHIFT
			0xA1 => 344, // VK_RSHIFT -> RSHIFT
			0xA2 => 341, // VK_LCONTROL -> LCTRL
			0xA3 => 345, // VK_RCONTROL -> RCTRL
			0xA4 => 342, // VK_LMENU -> LALT
			0xA5 => 346, // VK_RMENU -> RALT
			0xBA => 59,  // VK_OEM_1 -> ;
			0xBB => 61,  // VK_OEM_PLUS -> =
			0xBC => 44,  // VK_OEM_COMMA -> ,
			0xBD => 45,  // VK_OEM_MINUS -> -
			0xBE => 46,  // VK_OEM_PERIOD -> .
			0xBF => 47,  // VK_OEM_2 -> /
			0xC0 => 96,  // VK_OEM_3 -> `
			0xDB => 91,  // VK_OEM_4 -> [
			0xDC => 92,  // VK_OEM_5 -> \
			0xDD => 93,  // VK_OEM_6 -> ]
			0xDE => 39,  // VK_OEM_7 -> '
		];
		// F-keys
		for ($i = 0; $i < 12; $i++) {
			$map[0x70 + $i] = 290 + $i; // VK_F1..F12 -> GLFW F1..F12
		}
		// 0-9 and A-Z share the same codes
		if ($vk >= 0x30 && $vk <= 0x39) return $vk; // 0-9
		if ($vk >= 0x41 && $vk <= 0x5A) return $vk; // A-Z
		return $map[$vk] ?? $vk;
	}

	/** Open a native file chooser dialog and return the selected file path, or null if cancelled */
	private function openFileChooser($filter = "All Files\0*.*\0")
	{
		$cmd = 'powershell -NoProfile -Command "Add-Type -AssemblyName System.Windows.Forms; $f = New-Object System.Windows.Forms.OpenFileDialog; $f.Filter = \"All Files|*.*|PNG Files|*.png|Music Files|*.mp3;*.ogg;*.wav\"; if ($f.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) { Write-Output $f.FileName }"';
		$result = trim(shell_exec($cmd) ?? "");
		return !empty($result) && file_exists($result) ? $result : null;
	}

	private function installFoxyClientMod()
	{
		if ($this->isInstallingFoxyMod) return;
		$this->isInstallingFoxyMod = true;
		$this->foxyInstallProgress = "Downloading...";
		$this->needsRedraw = true;

		$gameDir = $this->getAbsolutePath($this->settings["game_dir"]);
		$modsDir = $gameDir . DIRECTORY_SEPARATOR . "mods";
		$cacert = $this->getAbsolutePath(self::CACERT);

		$this->foxyModInstallChannel = new \parallel\Channel(16);
		$ch = $this->foxyModInstallChannel;

		$this->foxyModInstallProcess = new \parallel\Runtime();
		$this->foxyModInstallFuture = $this->foxyModInstallProcess->run(
			static function ($modsDir, $cacert, $ch) {
				try {
					if (!is_dir($modsDir)) {
						mkdir($modsDir, 0777, true);
					}

					// Cleanup old versions
					foreach (scandir($modsDir) as $file) {
						if (preg_match('/^foxyclient-.*\.jar$/i', $file)) {
							@unlink($modsDir . DIRECTORY_SEPARATOR . $file);
						}
					}
					
					$ch->send("Fetching latest release...");

					// GitHub API
					$apiUrl = "https://api.github.com/repos/Minosuko/FoxyClientMod/releases/latest";
					
					$ch_curl = curl_init($apiUrl);
					curl_setopt($ch_curl, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch_curl, CURLOPT_USERAGENT, "FoxyClient");
					curl_setopt($ch_curl, CURLOPT_TIMEOUT, 30);
					if (file_exists($cacert)) {
						curl_setopt($ch_curl, CURLOPT_CAINFO, $cacert);
					}
					
					$response = curl_exec($ch_curl);
					curl_close($ch_curl);
					
					if ($response === false) {
						$ch->send("ERROR:Could not reach GitHub API");
						return;
					}
					$release = json_decode($response, true);
					if (!$release || !isset($release["assets"])) {
						$ch->send("ERROR:No release assets found");
						return;
					}

					$jarAsset = null;
					foreach ($release["assets"] as $asset) {
						if (str_ends_with($asset["name"], ".jar")) {
							$jarAsset = $asset;
							break;
						}
					}
					if (!$jarAsset) {
						$ch->send("ERROR:No JAR asset in release");
						return;
					}

					$ch_dl = curl_init($jarAsset["browser_download_url"]);
					curl_setopt($ch_dl, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch_dl, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($ch_dl, CURLOPT_USERAGENT, "FoxyClient");
					curl_setopt($ch_dl, CURLOPT_HTTPHEADER, ["Accept: application/octet-stream"]);
					curl_setopt($ch_dl, CURLOPT_TIMEOUT, 120);
					if (file_exists($cacert)) {
						curl_setopt($ch_dl, CURLOPT_CAINFO, $cacert);
					}
					
					$jarData = curl_exec($ch_dl);
					curl_close($ch_dl);
					
					if ($jarData === false) {
						$ch->send("ERROR:Download failed");
						return;
					}

					$dst = $modsDir . DIRECTORY_SEPARATOR . $jarAsset["name"];
					file_put_contents($dst, $jarData);
					
					$ch->send("Downloading Baritone...");
					$baritoneUrl = "http://cdn.foxyclient.qzz.io/baritone-1.21.11.jar";
					$ch_bari = curl_init($baritoneUrl);
					curl_setopt($ch_bari, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch_bari, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($ch_bari, CURLOPT_USERAGENT, "FoxyClient");
					curl_setopt($ch_bari, CURLOPT_TIMEOUT, 60);
					$bariData = curl_exec($ch_bari);
					$bariHttpCode = curl_getinfo($ch_bari, CURLINFO_HTTP_CODE);
					curl_close($ch_bari);

					if ($bariData !== false && $bariHttpCode === 200) {
						file_put_contents($modsDir . DIRECTORY_SEPARATOR . "baritone-1.21.11.jar", $bariData);
						$ch->send("DONE:Installed " . $jarAsset["name"] . " and Baritone");
					} else {
						$ch->send("DONE:Installed " . $jarAsset["name"] . " (Baritone failed)");
					}
				} catch (\Throwable $e) {
					$ch->send("ERROR:" . $e->getMessage());
				}
			},
			[$modsDir, $cacert, $ch],
		);
	}

	/**
	 * Draw a premium styled button with gradient, hover glow, and consistent styling.
	 * @param float $x X position
	 * @param float $y Y position
	 * @param float $w Width
	 * @param float $h Height
	 * @param string $label Button text
	 * @param bool $isHover Whether mouse is over the button
	 * @param string $style "primary"|"danger"|"secondary"|"success"
	 * @return void
	 */
	private function drawStyledButton($x, $y, $w, $h, $label, $isHover, $style = "primary", $fontSize = 1000)
	{
		$isLight = ($this->settings["theme"] ?? "dark") === "light";

		switch ($style) {
			case "danger":
				$c1 = $isHover ? [0.85, 0.2, 0.15] : [0.7, 0.15, 0.1];
				$c2 = $isHover ? [0.65, 0.1, 0.08] : [0.5, 0.08, 0.05];
				$textColor = [1, 1, 1];
				break;
			case "secondary":
				$c1 = $isHover
					? ($isLight ? [0.82, 0.84, 0.88] : [0.2, 0.22, 0.26])
					: ($isLight ? [0.88, 0.9, 0.93] : [0.14, 0.16, 0.2]);
				$c2 = $isHover
					? ($isLight ? [0.78, 0.8, 0.84] : [0.16, 0.18, 0.22])
					: ($isLight ? [0.84, 0.86, 0.89] : [0.1, 0.12, 0.16]);
				$textColor = $this->colors["text"];
				break;
			case "success":
				$c1 = $isHover ? [0.15, 0.75, 0.4] : [0.1, 0.6, 0.3];
				$c2 = $isHover ? [0.08, 0.55, 0.25] : [0.05, 0.45, 0.2];
				$textColor = [1, 1, 1];
				break;
			default: // primary
				$c1 = $isHover
					? $this->colors["button_hover"]
					: $this->colors["button"];
				$c2 = $isHover
					? $this->colors["primary"]
					: $this->colors["primary_dim"];
				$textColor = $this->colors["button_text"];
				break;
		}

		// Hover glow (subtle outer glow)
		if ($isHover) {
			$glowColor = [$c1[0], $c1[1], $c1[2], 0.15];
			$this->drawRect($x - 2, $y - 2, $w + 4, $h + 4, $glowColor);
		}

		// Gradient fill
		$this->drawGradientRect($x, $y, $w, $h, $c1, $c2);

		// Top highlight line (subtle glass effect)
		$highlightColor = [1.0, 1.0, 1.0, $isHover ? 0.15 : 0.08];
		$this->drawRect($x, $y, $w, 1, $highlightColor);

		// Text (centered)
		$textW = $this->getTextWidth($label, $fontSize);
		$textX = $x + ($w - $textW) / 2;
		$textY = $y + $h / 2 + ($fontSize === 2000 ? 10 : 6);
		$this->renderText($label, $textX, $textY, $textColor, $fontSize);
	}

	/**
	 * Reusable gradient page header with top accent and bottom divider.
	 */
	private function drawPageHeader($title, $subtitle, $rightContent = null)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$isLight = ($this->settings["theme"] ?? "dark") === "light";

		// Gradient header background
		$hc1 = $isLight ? [0.94, 0.95, 0.98, 0.9] : [0.06, 0.07, 0.1, 0.9];
		$hc2 = $isLight ? [0.9, 0.92, 0.95, 0.7] : [0.03, 0.04, 0.06, 0.7];
		$this->drawGradientRect(0, 0, $cw, self::HEADER_H, $hc1, $hc2);

		// Top accent strip (primary color - 2px)
		$this->drawRect(0, 0, $cw, 2, $this->colors["primary"]);

		// Bottom divider
		$this->drawRect(0, self::HEADER_H - 1, $cw, 1, $this->colors["divider"]);

		// Title
		$this->renderText($title, self::PAD, 38, $this->colors["primary"], 2000);

		// Subtitle
		$this->renderText($subtitle, self::PAD, 58, $this->colors["text_dim"], 1000);
	}

	/**
	 * Standardized card with hover glow, active accent bar, and top divider.
	 */
	private function drawCard($x, $y, $w, $h, $isHover = false, $isActive = false)
	{
		$isLight = ($this->settings["theme"] ?? "dark") === "light";

		// Hover glow (subtle outer)
		if ($isHover) {
			$glowC = $this->colors["primary"];
			$this->drawRect($x - 1, $y - 1, $w + 2, $h + 2, [$glowC[0], $glowC[1], $glowC[2], 0.08]);
		}

		// Card background
		$bgColor = $isActive
			? ($isLight ? [0.88, 0.9, 0.96, 0.6] : [0.12, 0.16, 0.22, 0.6])
			: ($isHover ? $this->colors["card_hover"] : $this->colors["card"]);
		$this->drawRect($x, $y, $w, $h, $bgColor);

		// Top divider line
		$this->drawRect($x, $y, $w, 1, $this->colors["divider"]);

		// Active accent bar (left edge)
		if ($isActive) {
			$this->drawRect($x, $y, 3, $h, $this->colors["primary"]);
		}
	}

	/**
	 * Modern pill-shaped toggle switch.
	 */
	private function drawToggleSwitch($x, $y, $isOn, $isHover = false)
	{
		$trackW = 44;
		$trackH = 22;
		$knobSize = 16;
		$padding = 3;

		// Track background
		if ($isOn) {
			$trackColor = $isHover ? $this->colors["button_hover"] : $this->colors["primary"];
		} else {
			$trackColor = $isHover ? $this->colors["card_hover"] : $this->colors["check_off"];
		}
		$this->drawRect($x, $y, $trackW, $trackH, $trackColor);

		// Subtle inner border
		$borderColor = $isOn ? [1, 1, 1, 0.1] : [1, 1, 1, 0.05];
		$this->drawRect($x, $y, $trackW, 1, $borderColor);

		// Knob position (animate would need lerp, for now snap)
		$knobX = $isOn ? $x + $trackW - $knobSize - $padding : $x + $padding;
		$knobY = $y + $padding;
		$knobColor = $isOn ? [1, 1, 1] : [0.6, 0.6, 0.65];

		$this->drawRect($knobX, $knobY, $knobSize, $knobSize, $knobColor);

		// Knob highlight
		$this->drawRect($knobX, $knobY, $knobSize, 1, [1, 1, 1, 0.3]);
	}

	/**
	 * Glassmorphic dropdown selector box with arrow indicator.
	 */
	private function drawDropdownSelector($x, $y, $w, $h, $label, $isOpen = false, $isHover = false)
	{
		$isLight = ($this->settings["theme"] ?? "dark") === "light";

		// Hover glow
		if ($isHover && !$isOpen) {
			$glowC = $this->colors["primary"];
			$this->drawRect($x - 1, $y - 1, $w + 2, $h + 2, [$glowC[0], $glowC[1], $glowC[2], 0.06]);
		}

		// Background
		$bg = $isOpen
			? ($isLight ? [0.86, 0.88, 0.92] : [0.1, 0.12, 0.16])
			: ($isHover
				? $this->colors["card_hover"]
				: $this->colors["card"]);
		$this->drawRect($x, $y, $w, $h, $bg);

		// Left accent bar
		$this->drawRect($x, $y, 2, $h, $this->colors["primary"]);

		// Top glass highlight
		$this->drawRect($x, $y, $w, 1, [1, 1, 1, 0.06]);

		// Label text
		$this->renderText($label, $x + 14, $y + $h / 2 + 6, $this->colors["text"], 1000);

		// Arrow indicator
		$arrow = $isOpen ? "▴" : "▾";
		$this->renderText($arrow, $x + $w - 24, $y + $h / 2 + 6, $this->colors["text_dim"], 1000);
	}

	private function renderLoginPage()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$boxW = 300;
		$boxX = ($cw - $boxW) / 2;

		// Back button
		$isBackHover =
			$this->mouseY >= 20 &&
			$this->mouseY <= 50 &&
			$this->mouseX >= self::SIDEBAR_W + self::PAD &&
			$this->mouseX <= self::SIDEBAR_W + self::PAD + 80;
		$backColor = $isBackHover
			? $this->colors["text"]
			: $this->colors["text_dim"];
		$this->renderText("< BACK", self::PAD, 40, $backColor, 1000);

		if ($this->loginStep === 0) {
			$this->renderText(
				"SELECT ACCOUNT TYPE",
				($cw - 250) / 2,
				80,
				$this->colors["text"],
				2000,
			);

			$y = 120;
			$types = [
				["id" => self::ACC_OFFLINE, "name" => "OFFLINE ACCOUNT"],
				["id" => self::ACC_MICROSOFT, "name" => "MICROSOFT ACCOUNT"],
				["id" => self::ACC_FOXY, "name" => "FOXYCLIENT ACCOUNT"],
				["id" => self::ACC_ELYBY, "name" => "ELY.BY ACCOUNT"],
			];
			foreach ($types as $type) {
				// Adjust hover check for sidebar width
				$isHover =
					$this->mouseY >= $y + self::TITLEBAR_H &&
					$this->mouseY <= $y + 40 + self::TITLEBAR_H &&
					$this->mouseX >= $boxX + self::SIDEBAR_W &&
					$this->mouseX <= $boxX + self::SIDEBAR_W + $boxW;
				$this->drawStyledButton($boxX, $y, $boxW, 40, $type["name"], $isHover, "secondary");
				$y += 50;
			}
			return;
		}

		$this->renderText(
			strtoupper($this->loginType) . " LOGIN",
			($cw - 200) / 2,
			60,
			$this->colors["text"],
			2000,
		);

		if ($this->loginType === self::ACC_OFFLINE) {
			$this->renderText(
				"Username",
				$boxX,
				190,
				$this->colors["text_dim"],
				3000,
			);
			$borderColor =
				$this->inputFocus === true
					? $this->colors["primary"]
					: $this->colors["divider"];
			$this->drawRect($boxX, 200, $boxW, 40, $this->colors["card"]);
			$this->drawRect($boxX, 238, $boxW, 2, $borderColor);
			$displayText =
				$this->loginInput . ($this->inputFocus === true ? "_" : "");
			if (empty($this->loginInput) && !$this->inputFocus) {
				$this->renderText(
					"Enter username...",
					$boxX + 10,
					226,
					$this->colors["text_dim"],
					1000,
				);
			} else {
				$this->renderText(
					$displayText,
					$boxX + 10,
					226,
					$this->colors["text"],
					1000,
				);
			}

			$isHover = $this->mouseY >= 260 + self::TITLEBAR_H && $this->mouseY <= 300 + self::TITLEBAR_H &&
					   $this->mouseX >= $boxX + self::SIDEBAR_W && $this->mouseX <= $boxX + self::SIDEBAR_W + $boxW;
			$this->drawStyledButton($boxX, 260, $boxW, 40, "LOGIN", $isHover, "success");
		} elseif ($this->loginType === self::ACC_ELYBY) {
			// Method Selector
			$this->renderText("LOGIN WITH ELY.BY", $boxX, 150, $this->colors["primary"], 2000);
			
			$isOAuthHover = $this->mouseY >= 175 + self::TITLEBAR_H && $this->mouseY <= 205 + self::TITLEBAR_H &&
							$this->mouseX >= $boxX + self::SIDEBAR_W && $this->mouseX <= $boxX + ($boxW - 10) / 2 + self::SIDEBAR_W;
			$isClassicHover = $this->mouseY >= 175 + self::TITLEBAR_H && $this->mouseY <= 205 + self::TITLEBAR_H &&
							  $this->mouseX >= $boxX + ($boxW - 10) / 2 + 10 + self::SIDEBAR_W && $this->mouseX <= $boxX + $boxW + self::SIDEBAR_W;
			
			$btnW = ($boxW - 10) / 2;
			$this->drawStyledButton($boxX, 175, $btnW, 30, "OAUTH2", $isOAuthHover, $this->elyLoginMethod === "oauth2" ? "primary" : "secondary");
			$this->drawStyledButton($boxX + $btnW + 10, 175, $btnW, 30, "CLASSIC", $isClassicHover, $this->elyLoginMethod === "classic" ? "primary" : "secondary");

			if ($this->elyLoginMethod === "oauth2") {
				$this->renderText("Link your account automatically via browser.", $boxX, 220, $this->colors["text_dim"], 1000);

				$isHoverLink = $this->mouseY >= 240 + self::TITLEBAR_H && $this->mouseY <= 280 + self::TITLEBAR_H &&
							   $this->mouseX >= $boxX + self::SIDEBAR_W && $this->mouseX <= $boxX + self::SIDEBAR_W + $boxW;
				$this->drawStyledButton($boxX, 240, $boxW, 40, "LINK ELY.BY ACCOUNT", $isHoverLink, "primary");

				if ($this->loginStep === 1) {
					$this->renderText("Waiting for authentication in browser...", $boxX, 300, $this->colors["text_dim"], 1000);
				}
			} else {
				// Classic Login UI
				$this->renderText("Email / Username", $boxX, 215, $this->colors["text_dim"], 3000);
				$borderU = $this->inputFocus === "username" ? $this->colors["primary"] : $this->colors["divider"];
				$this->drawRect($boxX, 225, $boxW, 40, $this->colors["card"]);
				$this->drawRect($boxX, 263, $boxW, 2, $borderU);
				$displayU = $this->loginInput . ($this->inputFocus === "username" ? "_" : "");
				$this->renderText($displayU ?: "Email...", $boxX + 10, 251, $displayU ? $this->colors["text"] : $this->colors["text_dim"], 1000);

				$this->renderText("Password", $boxX, 285, $this->colors["text_dim"], 3000);
				$borderP = $this->inputFocus === "password" ? $this->colors["primary"] : $this->colors["divider"];
				$this->drawRect($boxX, 295, $boxW, 40, $this->colors["card"]);
				$this->drawRect($boxX, 333, $boxW, 2, $borderP);
				$displayP = str_repeat("*", strlen($this->loginInputPassword)) . ($this->inputFocus === "password" ? "_" : "");
				$this->renderText($displayP ?: "Password...", $boxX + 10, 321, $displayP ? $this->colors["text"] : $this->colors["text_dim"], 1000);

				$isHoverAuth = $this->mouseY >= 360 + self::TITLEBAR_H && $this->mouseY <= 400 + self::TITLEBAR_H &&
							   $this->mouseX >= $boxX + self::SIDEBAR_W && $this->mouseX <= $boxX + self::SIDEBAR_W + $boxW;
				$this->drawStyledButton($boxX, 360, $boxW, 40, "LOGIN (CLASSIC)", $isHoverAuth, "success");
			}

			if ($this->msError) {
				$this->renderText($this->msError, ($cw - $this->getTextWidth($this->msError, 1000)) / 2, 460, [1, 0.4, 0.4], 1000);
			}
		} elseif ($this->loginType === self::ACC_FOXY) {
			// Method Selector
			$this->renderText("LOGIN WITH FOXYCLIENT", $boxX, 150, $this->colors["primary"], 2000);
			
			$isOAuthHover = $this->mouseY >= 175 + self::TITLEBAR_H && $this->mouseY <= 205 + self::TITLEBAR_H &&
							$this->mouseX >= $boxX + self::SIDEBAR_W && $this->mouseX <= $boxX + ($boxW - 10) / 2 + self::SIDEBAR_W;
			$isClassicHover = $this->mouseY >= 175 + self::TITLEBAR_H && $this->mouseY <= 205 + self::TITLEBAR_H &&
							  $this->mouseX >= $boxX + ($boxW - 10) / 2 + 10 + self::SIDEBAR_W && $this->mouseX <= $boxX + $boxW + self::SIDEBAR_W;
			
			$btnW = ($boxW - 10) / 2;
			$this->drawStyledButton($boxX, 175, $btnW, 30, "OAUTH2", $isOAuthHover, $this->foxyLoginMethod === "oauth2" ? "primary" : "secondary");
			$this->drawStyledButton($boxX + $btnW + 10, 175, $btnW, 30, "CLASSIC", $isClassicHover, $this->foxyLoginMethod === "classic" ? "primary" : "secondary");

			if ($this->foxyLoginMethod === "oauth2") {
				$this->renderText("Authenticate securely via the FoxyClient dashboard.", $boxX, 220, $this->colors["text_dim"], 1000);

				$isHoverLink = $this->mouseY >= 240 + self::TITLEBAR_H && $this->mouseY <= 280 + self::TITLEBAR_H &&
							   $this->mouseX >= $boxX + self::SIDEBAR_W && $this->mouseX <= $boxX + self::SIDEBAR_W + $boxW;
				$this->drawStyledButton($boxX, 240, $boxW, 40, "LINK FOXY ACCOUNT", $isHoverLink, "primary");

				if ($this->loginStep === 1) {
					$this->renderText("Waiting for authentication in browser...", $boxX, 300, $this->colors["text_dim"], 1000);
				}
			} else {
				// Classic Login UI for FoxyClient
				$this->renderText("Email / Username", $boxX, 215, $this->colors["text_dim"], 3000);
				$borderU = $this->inputFocus === "username" ? $this->colors["primary"] : $this->colors["divider"];
				$this->drawRect($boxX, 225, $boxW, 40, $this->colors["card"]);
				$this->drawRect($boxX, 263, $boxW, 2, $borderU);
				$displayU = (string)($this->loginInput ?? "") . ($this->inputFocus === "username" ? "_" : "");
				$this->renderText($displayU !== ($this->inputFocus === "username" ? "_" : "") ? $displayU : "Email...", $boxX + 10, 251, $displayU !== ($this->inputFocus === "username" ? "_" : "") ? $this->colors["text"] : $this->colors["text_dim"], 1000);

				$this->renderText("Password", $boxX, 285, $this->colors["text_dim"], 3000);
				$borderP = $this->inputFocus === "password" ? $this->colors["primary"] : $this->colors["divider"];
				$this->drawRect($boxX, 295, $boxW, 40, $this->colors["card"]);
				$this->drawRect($boxX, 333, $boxW, 2, $borderP);
				$displayP = str_repeat("*", strlen($this->loginInputPassword ?? "")) . ($this->inputFocus === "password" ? "_" : "");
				$this->renderText($displayP !== ($this->inputFocus === "password" ? "_" : "") ? $displayP : "Password...", $boxX + 10, 321, $displayP !== ($this->inputFocus === "password" ? "_" : "") ? $this->colors["text"] : $this->colors["text_dim"], 1000);

				$isHoverAuth = $this->mouseY >= 360 + self::TITLEBAR_H && $this->mouseY <= 400 + self::TITLEBAR_H &&
							   $this->mouseX >= $boxX + self::SIDEBAR_W && $this->mouseX <= $boxX + self::SIDEBAR_W + $boxW;
				$this->drawStyledButton($boxX, 360, $boxW, 40, "LOGIN (CLASSIC)", $isHoverAuth, "success");
			}

			if ($this->msError) {
				$this->renderText($this->msError, ($cw - $this->getTextWidth($this->msError, 1000)) / 2, 460, [1, 0.4, 0.4], 1000);
			}
		} elseif ($this->loginType === self::ACC_MICROSOFT) {
			if ($this->msUserCode) {
				$this->renderText(
					"Please visit:",
					$boxX,
					150,
					$this->colors["text_dim"],
					1000,
				);
				$this->renderText(
					$this->msVerificationUri,
					$boxX,
					175,
					$this->colors["primary"],
					3000,
				);
				$this->renderText(
					"And enter code:",
					$boxX,
					220,
					$this->colors["text_dim"],
					1000,
				);
				$this->renderText(
					$this->msUserCode,
					$boxX,
					260,
					$this->colors["text"],
					2000,
				);

				$this->renderText(
					"Waiting for authentication...",
					$boxX,
					300,
					$this->colors["text_dim"],
					3000,
				);
			} else {
				$this->renderText(
					"Connecting to Microsoft...",
					$boxX,
					150,
					$this->colors["text_dim"],
					1000,
				);
			}

			if ($this->msError) {
				$this->renderText(
					"Error: " . $this->msError,
					$boxX,
					380,
					[1, 0.4, 0.4],
					3000,
				);
			}

			$isCancelHover = $this->mouseY >= 330 && $this->mouseY <= 370 &&
					   $this->mouseX >= $boxX + self::SIDEBAR_W && $this->mouseX <= $boxX + self::SIDEBAR_W + $boxW;
			$this->drawStyledButton($boxX, 330, $boxW, 40, "CANCEL", $isCancelHover, "secondary");
		}
	}

	private function renderVersionsPage()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$gl = $this->opengl32;
		
		$this->drawPageHeader("VERSION MANAGER", "Select target Minecraft version");

		// Category Tabs
		$y = 100;
		$cats = ["RELEASES", "SNAPSHOTS", "MODIFIED"];
		$tx = self::PAD;

		$tabBg = $this->bgTex ? [$this->colors["tab_bg"][0], $this->colors["tab_bg"][1], $this->colors["tab_bg"][2], 0.7] : $this->colors["tab_bg"];
		$this->drawRect(0, $y, $cw, self::TAB_H, $tabBg);
		$this->drawRect(0, $y + self::TAB_H - 1, $cw, 1, $this->colors["divider"]);

		foreach ($cats as $i => $cat) {
			$isActive = $this->vCategory === $i;
			$isHover = $this->vTabHover === $i;
			$tw = $this->getTextWidth($cat, 1000) + 30;

			if ($isActive) {
				$this->drawRect($tx, $y, $tw, self::TAB_H, $this->colors["tab_active"]);
				$this->drawRect($tx, $y + self::TAB_H - 3, $tw, 3, $this->colors["primary"]);
				$this->renderText($cat, $tx + 15, $y + 26, $this->colors["text"], 1000);
			} else {
				if ($isHover) {
					$this->drawRect($tx, $y, $tw, self::TAB_H, $this->colors["card_hover"]);
				}
				$this->renderText($cat, $tx + 15, $y + 26, $this->colors["text_dim"], 1000);
			}
			$tx += $tw;
		}

		if (!$this->versionsLoaded) {
			if ($this->isFetchingVersions) {
				$this->renderText("Fetching versions...", self::PAD, 180, $this->colors["text_dim"], 1000);
			} elseif (!empty($this->vManifestError)) {
				$this->renderText("ERROR: " . $this->vManifestError, self::PAD, 180, $this->colors["status_error"], 1000);
				$this->renderText("Check your internet connection.", self::PAD, 205, $this->colors["text_dim"], 1000);
				
				// Retry button (using hover state 999)
				$isRetryHover = $this->vHoverIndex === 999;
				$this->drawStyledButton(self::PAD, 230, 100, 36, "RETRY", $isRetryHover, "primary");
			} else {
				$this->loadVersions();
			}
			return;
		}

		$filtered = $this->getFilteredVersions();

		// Version List (with viewport clipping)
		$usableH = $this->height - self::TITLEBAR_H;
		$listTop = $y + self::TAB_H;
		$bottomMargin = 150;
		$listH = $usableH - $listTop - $bottomMargin;

		$y = $listTop - $this->scrollOffset;

		$vy = $listTop - $this->vScrollOffset;
		$cardH = 56;
		$gap = 6;
		
		$gl->glEnable(0x0c11); // SCISSOR
		$gl->glScissor(
			self::SIDEBAR_W,
			$this->height - ($listTop + self::TITLEBAR_H + $listH),
			$cw,
			$listH
		);

		foreach ($filtered as $i => $v) {
			if ($vy + $cardH > $listTop && $vy < $listTop + $listH) {
				$isHover = $this->vHoverIndex === $i;
				$isSelected = $this->selectedVersion === $v["id"];

				$jarPath = $this->settings["game_dir"] . DIRECTORY_SEPARATOR . "versions" . DIRECTORY_SEPARATOR . $v["id"] . DIRECTORY_SEPARATOR . $v["id"] . ".jar";
				$isVersionInstalled = file_exists($jarPath);

				$this->drawCard(self::PAD, $vy, $cw - self::PAD * 2, $cardH, $isHover, $isSelected);


				$textColor = $isSelected || $isHover ? $this->colors["text"] : $this->colors["text_dim"];
				$this->renderText($v["id"], self::PAD + 16, $vy + 32, $textColor, 2000);

				$statusText = $isVersionInstalled ? "Installed" : "Available";
				$statusColor = $isVersionInstalled ? $this->colors["status_update"] : $this->colors["text_dim"];
				
				// Status text
				$this->renderText($statusText, $cw - self::PAD - 120, $vy + 32, $statusColor, 1000);
				
				// Type badge
				$type = ucfirst($v["type"]);
				$twType = $this->getTextWidth($type, 3000) + 12;
				$this->drawRect($cw - self::PAD - 140 - $twType, $vy + 20, $twType, 16, [0,0,0,0.2]);
				$this->renderText($type, $cw - self::PAD - 140 - $twType + 6, $vy + 31, $this->colors["text_dim"], 3000);
			}
			$vy += $cardH + $gap;
		}

		$gl->glDisable(0x0c11);


		// Render scrollbar
		$this->renderScrollbarVersions($listTop, $listH);

		// Action area
		$actionY = $usableH - $bottomMargin;
		$this->drawRect(0, $actionY, $cw, 1, $this->colors["divider"]);

		$isInstalled = false;
		if ($this->selectedVersion) {
			$jarPath = $this->settings["game_dir"] . DIRECTORY_SEPARATOR . "versions" . DIRECTORY_SEPARATOR . $this->selectedVersion . DIRECTORY_SEPARATOR . $this->selectedVersion . ".jar";
			$isInstalled = file_exists($jarPath);
		}
		$statusBadge = $this->selectedVersion ? ($isInstalled ? "(Installed)" : "(Available)") : "";
		$this->renderText("Current Selection: " . ($this->selectedVersion ?: "None") . " " . $statusBadge, self::PAD, $actionY + 25, $this->colors["text"], 1000);

		if ($this->isDownloadingAssets) {
			$barW = $cw - self::PAD * 2;
			$this->drawRect(self::PAD, $actionY + 45, $barW, 8, $this->colors["card"]);
			if ($this->assetProgress > 0) {
				$this->drawRect(self::PAD, $actionY + 45, $barW * $this->assetProgress, 8, $this->colors["primary"]);
			}
			$msg = $this->assetMessage ?: "STARTING DOWNLOAD...";
			$this->renderText($msg, self::PAD, $actionY + 70, $this->colors["primary"], 1000);
		} else {
			$btnHover = $this->assetButtonHover;
			$btnText = $isInstalled ? "REINSTALL VERSION" : "DOWNLOAD VERSION";
			$btnStyle = $isInstalled ? "primary" : "success"; // different variant if available
			
			$btnW = 200;
			$btnH = 36;
			$this->drawStyledButton(self::PAD, $actionY + 45, $btnW, $btnH, $btnText, $btnHover, $btnStyle);

			if ($isInstalled) {
				$unW = 150;
				$this->drawStyledButton(self::PAD + $btnW + 16, $actionY + 45, $unW, $btnH, "UNINSTALL", $this->assetUninstallHover, "danger");
			}
		}
	}

	private function getFilteredVersions()
	{
		$showMod = (bool) ($this->settings["show_modified_versions"] ?? false);
		$cacheKey =
			$this->vCategory .
			($this->vCategory === 0 ? ($showMod ? "_mod" : "") : "");

		if (
			$this->filteredVersionsCache !== null &&
			$this->lastVCategory === $cacheKey
		) {
			return $this->filteredVersionsCache;
		}

		$type = "release";
		if ($this->vCategory === 1) {
			$type = "snapshot";
		}
		if ($this->vCategory === 2) {
			$type = "modified";
		}

		$out = [];
		foreach ($this->versions as $v) {
			$vType = $v["type"] ?? "";
			if ($vType === $type) {
				$out[] = $v;
			}
		}
		$this->filteredVersionsCache = $out;
		$this->lastVCategory = $cacheKey;
		return $out;
	}

	private function renderScrollbarVersions($y, $h)
	{
		$maxScroll = $this->getMaxVersionScroll();
		if ($maxScroll <= 0) {
			return;
		}

		$cw = $this->width - self::SIDEBAR_W;
		$barX = $cw - 12;
		$barW = 6;

		// Track
		$this->drawRect($barX, $y, $barW, $h, $this->colors["card"]);

		// Thumb
		$filtered = $this->getFilteredVersions();
		$contentH = count($filtered) * 40;
		$thumbH = max(20, ($h / $contentH) * $h);
		$thumbY = $y + ($this->vScrollOffset / $maxScroll) * ($h - $thumbH);

		$this->drawRect($barX, $thumbY, $barW, $thumbH, $this->colors["scrollbar"]);
	}

	private function loadVersions()
	{
		if ($this->isFetchingVersions) {
			return;
		}
		$this->isFetchingVersions = true;
		$this->vManifestError = "";

		$this->vManifestChannel = new \parallel\Channel();
		$this->vManifestProcess = new \parallel\Runtime(); // No custom classes needed here
		$this->vManifestFuture = $this->vManifestProcess->run(
			function (\parallel\Channel $ch) {
				$urls = [
					"mojang" =>
						"https://launchermeta.mojang.com/mc/game/version_manifest.json",
					"modified" =>
						"https://repo.llaun.ch/versions/versions.json",
				];
				$allVersions = [];
				foreach ($urls as $key => $url) {
					$curl = curl_init($url);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($curl, CURLOPT_TIMEOUT, 20);
					curl_setopt($curl, CURLOPT_USERAGENT, "FoxyClient");
					$data = curl_exec($curl);
					curl_close($curl);
					
					if ($data) {
						$manifest = json_decode($data, true);
						if (isset($manifest["versions"])) {
							$allVersions = array_merge(
								$allVersions,
								$manifest["versions"],
							);
						}
					}
				}
				if (!empty($allVersions)) {
					$ch->send(
						json_encode([
							"type" => "manifest",
							"versions" => $allVersions,
						]),
					);
					$ch->close();
					return;
				}
				$ch->send(
					json_encode([
						"type" => "error",
						"message" => "Failed to load manifests",
					]),
				);
				$ch->close();
			},
			[$this->vManifestChannel],
		);

		$this->pollEvents->addChannel($this->vManifestChannel);
	}

	private function renderModDetail($mod)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$tc = $this->colors["text"];
		$td = $this->colors["text_dim"];

		$topY = self::HEADER_H + self::TAB_H;
		$this->drawRect(self::PAD, $topY + 10, $cw - self::PAD * 2, 200, $this->colors["card"], 8);
		
		// Title
		$this->renderText($mod["name"], self::PAD + 20, $topY + 45, $tc, 2000);
		
		// Author
		$this->renderText("By " . ($mod["author"] ?? "Unknown"), self::PAD + 20, $topY + 70, $td, 3000);
		
		// Status Badge
		$statusColor = ($mod["enabled"] ?? true) ? $this->colors["status_done"] : $this->colors["status_error"];
		$this->drawRect(self::PAD + 20, $topY + 85, 90, 22, [$statusColor[0], $statusColor[1], $statusColor[2], 0.2], 4);
		$this->renderText(($mod["enabled"] ?? true) ? "ENABLED" : "DISABLED", self::PAD + 28, $topY + 102, $statusColor, 3000);
	}

	private function renderAccountsPage()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$gl = $this->opengl32;

		$this->drawPageHeader("ACCOUNT MANAGER", "Manage your Minecraft profiles");

		// Add Account Button (Header Right)
		$addBtnW = 150;
		$addBtnH = 36;
		$addBtnX = $cw - self::PAD - $addBtnW;
		$addBtnY = 32;
		$isAddHover = $this->accHoverIndex === "add_btn";
		$this->drawStyledButton($addBtnX, $addBtnY, $addBtnW, $addBtnH, "+ ADD ACCOUNT", $isAddHover, "success");

		$listTop = 110;
		$usableH = $this->height - self::TITLEBAR_H;
		$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
		$listH = $usableH - $footerH - $listTop;

		$itemH = 64;
		$gap = 6;
		$y = $listTop - $this->accScrollOffset;

		foreach ($this->accounts as $uuid => $accData) {
			if ($y + $itemH > $listTop && $y < $listTop + $listH) {
				$isActive = $this->activeAccount === $uuid;
				$isHover = $this->accHoverIndex === $uuid;

				$this->drawCard(self::PAD, $y, $cw - self::PAD * 2, $itemH, $isHover, $isActive);


				$username = $accData["Username"] ?? "Unknown";
				$type = $accData["Type"] ?? self::ACC_OFFLINE;
				$tex = null;
				$typeLabel = "Offline";
				$typeColor = $this->colors["text_dim"];

				if ($type === self::ACC_MICROSOFT) {
					$tex = $this->mojangTex;
					$typeLabel = "Microsoft User";
					$typeColor = [0.1, 0.7, 0.4];
				} elseif ($type === self::ACC_ELYBY) {
					$tex = $this->elybyTex;
					$typeLabel = "Ely.by User";
					$typeColor = [0.2, 0.5, 0.9];
				} elseif ($type === self::ACC_FOXY) {
					$tex = $this->logoTex;
					$typeLabel = "Foxy User";
					$typeColor = $this->colors["primary"];
				}

				// Small head/icon placeholder
				if ($tex) {
					$this->drawTexture($tex, self::PAD + 16, $y + 16, 32, 32);
				} else {
					$this->drawRect(self::PAD + 16, $y + 16, 32, 32, $this->colors["panel"]);
					$this->renderText("?", self::PAD + 26, $y + 40, $this->colors["text_dim"], 1000);
				}

				$this->renderText($username, self::PAD + 64, $y + 30, $this->colors["text"], 1000);
				
				// Type badge
				$badgeW = $this->getTextWidth($typeLabel, 3000) + 12;
				$this->drawRect(self::PAD + 64, $y + 38, $badgeW, 16, [0.0, 0.0, 0.0, 0.2]);
				$this->renderText($typeLabel, self::PAD + 70, $y + 49, $typeColor, 3000);

				// Delete button
				$delW = 32;
				$delH = 32;
				$delX = $cw - self::PAD - $delW - 16;
				$delY = $y + ($itemH - $delH) / 2;
				$isDelHover = $this->accHoverIndex === $uuid . "_del";
				$this->drawStyledButton($delX, $delY, $delW, $delH, "X", $isDelHover, "danger");

			}
			$y += $itemH + $gap;
		}



		// Scrollbar
		$contentH = count($this->accounts) * ($itemH + $gap);
		if ($contentH > $listH) {
			$thumbH = max(30, ($listH / $contentH) * $listH);
			$scrollRatio = $this->accScrollOffset / max(1, $contentH - $listH);
			$thumbY = $listTop + ($listH - $thumbH) * $scrollRatio;
			$this->drawRect($cw - 6, $listTop, 6, $listH, $this->colors["card"]);
			$this->drawRect($cw - 5, $thumbY, 4, $thumbH, $this->colors["scrollbar"]);
		}
	}

	private function renderPropertiesPage()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$this->renderText(
			"PROPERTIES",
			self::PAD,
			45,
			$this->colors["primary"],
			2000,
		);
		$this->renderText(
			"Configure FoxyClient and Minecraft settings",
			self::PAD,
			62,
			$this->colors["text_dim"],
			1000,
		);

		// Sub-tabs
		$y = self::HEADER_H;
		$this->drawRect(0, $y, $cw, self::TAB_H, $this->colors["tab_bg"]);
		$this->drawRect(
			0,
			$y + self::TAB_H - 1,
			$cw,
			1,
			$this->colors["divider"],
		);

		$tx = self::PAD;
		$cats = ["Minecraft", "Launcher", "Update", "About"];
		foreach ($cats as $i => $cat) {
			$isActive = $this->propSubTab === $i;
			$isHover = $this->propTabHover === $i;
			$tw = strlen($cat) * 8 + 30;

			if ($isActive) {
				$this->drawRect(
					$tx,
					$y,
					$tw,
					self::TAB_H,
					$this->colors["tab_active"],
				);
				$this->drawRect(
					$tx,
					$y + self::TAB_H - 3,
					$tw,
					3,
					$this->colors["primary"],
				);
				$this->renderText(
					$cat,
					$tx + 15,
					$y + 26,
					$this->colors["text"],
					1000,
				);
			} else {
				if ($isHover) {
					$isLight = ($this->settings["theme"] ?? "dark") === "light";
					$this->drawRect(
						$tx,
						$y,
						$tw,
						self::TAB_H,
						$isLight ? [0.85, 0.88, 0.92] : [0.11, 0.11, 0.12],
					);
				}
				$this->renderText(
					$cat,
					$tx + 15,
					$y + 26,
					$this->colors["text_dim"],
					1000,
				);
			}
			$tx += $tw + 4;
		}

		// Content Area
		$contentTop = self::HEADER_H + self::TAB_H + 20;
		$usableH = $this->height - self::TITLEBAR_H;
		$h = $usableH - $contentTop;

		$gl = $this->opengl32;
		$drawY = $contentTop - $this->propScrollOffset;

		$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
		$clipH = $usableH - $contentTop - $footerH - 20; // Bottom margin

		$gl->glEnable(0x0c11); // SCISSOR
		$gl->glScissor(
			self::SIDEBAR_W,
			$this->height - ($contentTop + self::TITLEBAR_H + $clipH),
			$cw,
			$clipH
		);

		if ($this->propSubTab === 0) {
			$this->renderPropertiesMinecraft($drawY);
		} elseif ($this->propSubTab === 1) {
			$this->renderPropertiesLauncher($drawY);
		} elseif ($this->propSubTab === 2) {
			$this->renderPropertiesUpdate($drawY);
		} elseif ($this->propSubTab === 3) {
			$this->renderPropertiesAbout($drawY);
		}

		$gl->glDisable(0x0c11);


		// Simple scrollbar logic
		$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
		$maxScroll = 400; // Increased for more rows
		if ($this->propScrollTarget > 0 || $this->propScrollOffset > 0) {
			$barX = $cw - 6;
			$this->drawRect($barX, $contentTop, 4, $clipH, $this->colors["card"]);
			$size = max(20, $clipH * ($clipH / ($maxScroll + $clipH)));
			$pos = ($clipH - $size) * ($this->propScrollOffset / $maxScroll);
			$this->drawRect(
				$barX,
				$contentTop + $pos,
				4,
				$size,
				$this->colors["scrollbar"],
			);
		}
	}

	private function renderPropRow($idx, $y, $label, $desc, $controlRenderer)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$rowH = 50;

		// Hover bg
		if ($this->propFieldHover === $idx) {
			$this->drawRect(
				self::PAD,
				$y,
				$cw - self::PAD * 2,
				$rowH,
				$this->colors["card_hover"],
			);
		}

		$this->renderText(
			$label,
			self::PAD + 10,
			$y + 20,
			$this->colors["text"],
			1000,
		);
		$this->renderText(
			$desc,
			self::PAD + 10,
			$y + 36,
			$this->colors["text_dim"],
			3000,
		);

		$fieldX = $cw - self::PAD - 300;
		$controlRenderer($fieldX, $y + 5, 300, 40);

		return $y + 60; // Returns next Y
	}

	private function renderPropTextField(
		$x,
		$y,
		$w,
		$h,
		$key,
		$placeholder = "",
	) {
		$isActive = $this->propActiveField === $key;
		$borderColor = $isActive
			? $this->colors["primary"]
			: $this->colors["divider"];

		$this->drawRect($x, $y, $w, $h, $this->colors["card"]);
		$this->drawRect($x, $y + $h - 2, $w, 2, $borderColor);

		$val = $this->settings[$key];
		$display = $val . ($isActive ? "_" : "");

		if (empty($val) && !$isActive) {
			$this->renderText(
				$placeholder,
				$x + 10,
				$y + 26,
				$this->colors["text_dim"],
				1000,
			);
		} else {
			// Clip text if too long (simple hack: substr)
			if (strlen($display) > 35) {
				$display = "..." . substr($display, -32);
			}
			$this->renderText(
				$display,
				$x + 10,
				$y + 26,
				$this->colors["text"],
				1000,
			);
		}
	}

	private function renderPropertiesMinecraft($y)
	{
		$y = $this->renderPropRow(
			0,
			$y,
			"Game Folder",
			"Where Minecraft files (games/) are stored",
			function ($x, $cy, $w, $h) {
				$this->renderPropTextField(
					$x,
					$cy,
					$w - 90,
					$h,
					"game_dir",
					"games",
				);

				// Browse button
				$bx = $x + $w - 80;
				$isHover =
					$this->mouseX >= $bx + self::SIDEBAR_W &&
					$this->mouseX <= $bx + self::SIDEBAR_W + 80 &&
					$this->mouseY >= $cy + self::TITLEBAR_H &&
					$this->mouseY <= $cy + self::TITLEBAR_H + 40;
				$this->drawStyledButton($bx, $cy, 80, 40, "BROWSE", $isHover, "accent");
			},
		);

		$y = $this->renderPropRow(
			1,
			$y,
			"Window Size",
			"Resolution of Minecraft window (W x H)",
			function ($x, $cy, $w, $h) {
				$this->renderPropTextField($x, $cy, 130, $h, "window_w", "854");
				$this->renderText(
					"x",
					$x + 145,
					$cy + 26,
					$this->colors["text_dim"],
					1000,
				);
				$this->renderPropTextField(
					$x + 170,
					$cy,
					130,
					$h,
					"window_h",
					"480",
				);
			},
		);

		$y = $this->renderPropRow(
			2,
			$y,
			"Java / JRE Settings",
			"Arguments and executable path configuration",
			function ($x, $cy, $w, $h) {
				// Expanded config button
				$isHover = $this->propFieldHover === 2;
				$this->drawStyledButton($x, $cy, $w, $h, "CONFIGURE ADVANCED SETTINGS", $isHover, "primary", 3000);
			},
		);

		$y = $this->renderPropRow(
			3,
			$y,
			"Allocated Memory",
			"RAM for Minecraft game (in MB)",
			function ($x, $cy, $w, $h) {
				$cur = (int) $this->settings["ram_mb"];

				// - btn
				$isMinusHover = $this->mouseX >= $x + self::SIDEBAR_W && $this->mouseX <= $x + self::SIDEBAR_W + 40 &&
							   $this->mouseY >= $cy + self::TITLEBAR_H && $this->mouseY <= $cy + self::TITLEBAR_H + $h;
				$this->drawStyledButton($x, $cy, 40, $h, "-", $isMinusHover, "secondary");

				// value center (editable)
				$this->renderPropTextField(
					$x + 50,
					$cy,
					$w - 100,
					$h,
					"ram_mb",
					"2048",
				);

				// + btn
				$isPlusHover = $this->mouseX >= $x + $w - 40 + self::SIDEBAR_W && $this->mouseX <= $x + $w + self::SIDEBAR_W &&
							  $this->mouseY >= $cy + self::TITLEBAR_H && $this->mouseY <= $cy + self::TITLEBAR_H + $h;
				$this->drawStyledButton($x + $w - 40, $cy, 40, $h, "+", $isPlusHover, "secondary");
			},
		);

		// Folder shortcut buttons
		$folderBtnRenderer = function($label) {
			return function($x, $cy, $w, $h) use ($label) {
				$bx = $x + 100;
				$bw = 200;
				$isHover = $this->mouseX >= $bx + self::SIDEBAR_W && $this->mouseX <= $bx + self::SIDEBAR_W + $bw &&
						   $this->mouseY >= $cy + self::TITLEBAR_H && $this->mouseY <= $cy + self::TITLEBAR_H + $h;
				$this->drawStyledButton($bx, $cy, $bw, $h, $label, $isHover, "primary");
			};
		};

		$y = $this->renderPropRow(4, $y, "Game Folder", "Open the main .minecraft directory in Explorer", $folderBtnRenderer("OPEN GAME FOLDER"));
		$y = $this->renderPropRow(5, $y, "Mods Folder", "Open the mods/ directory in Explorer", $folderBtnRenderer("OPEN MODS FOLDER"));
		$y = $this->renderPropRow(6, $y, "Texture Packs", "Open the resourcepacks/ directory in Explorer", $folderBtnRenderer("OPEN RESOURCEPACKS"));
		$y = $this->renderPropRow(7, $y, "Shader Packs", "Open the shaderpacks/ directory in Explorer", $folderBtnRenderer("OPEN SHADERPACKS"));
	}

	private function renderPropertiesLauncher($y)
	{
		$y = $this->renderPropRow(
			0,
			$y,
			"Background",
			"Configure launcher background image & blur",
			function ($x, $cy, $w, $h) {
				$isHover = $this->propFieldHover === 0;
				$this->drawStyledButton($x, $cy, $w, $h, "CONFIGURE BACKGROUND", $isHover, "primary", 3000);
			},
		);

		$y = $this->renderPropRow(
			1,
			$y,
			"Theme",
			"App UI Theme (Dark / Light)",
			function ($x, $cy, $w, $h) {
				$isDark = $this->settings["theme"] === "dark";
				$isHover = $this->propFieldHover === 1;
				$this->drawToggleSwitch($x + $w - 44, $cy + 9, $isDark, $isHover);
				
				$text = $isDark ? "DARK THEME" : "LIGHT THEME";
				$color = $this->colors["text_dim"];
				$this->renderText(
					$text,
					$x + $w - 150,
					$cy + 26,
					$color,
					1000,
				);
			},
		);

		$y = $this->renderPropRow(
			2,
			$y,
			"Language",
			"Current Launcher Language",
			function ($x, $cy, $w, $h) {
				$isHover = $this->propFieldHover === 2;
				$this->drawDropdownSelector($x, $cy, $w, $h, "English (en)", false, $isHover);
			},
		);

		$y = $this->renderPropRow(
			3,
			$y,
			"Show Modified Versions",
			"Include Forge, Fabric, etc. in Home dropdown",
			function ($x, $cy, $w, $h) {
				$showMod = (bool) ($this->settings["show_modified_versions"] ?? false);
				$isHover = $this->propFieldHover === 3;
				$this->drawToggleSwitch($x + $w - 44, $cy + 9, $showMod, $isHover);
			},
		);

		$y = $this->renderPropRow(
			4,
			$y,
			"Launcher Font",
			"Font used for the launcher interface",
			function ($x, $cy, $w, $h) {
				$isOpen = $this->propFontDropdownOpen === "launcher";
				$isHover = $this->propFieldHover === 4;
				$font = $this->settings["font_launcher"] ?? "Open Sans";
				$this->drawDropdownSelector($x, $cy, $w, $h, $font, $isOpen, $isHover);
			},
		);

		$y = $this->renderPropRow(
			5,
			$y,
			"Overlay Font",
			"Font used for the in-game overlay HUD",
			function ($x, $cy, $w, $h) {
				$isOpen = $this->propFontDropdownOpen === "overlay";
				$isHover = $this->propFieldHover === 5;
				$font = $this->settings["font_overlay"] ?? "Consolas";
				$this->drawDropdownSelector($x, $cy, $w, $h, $font, $isOpen, $isHover);
			},
		);

		$y = $this->renderPropRow(
			6,
			$y,
			"Separate Modpack Folders",
			"Store each modpack in its own dedicated versions/ folder",
			function ($x, $cy, $w, $h) {
				$enabled = (bool) ($this->settings["separate_modpack_folder"] ?? false);
				$isHover = $this->propFieldHover === 6;
				$this->drawToggleSwitch($x + $w - 44, $cy + 9, $enabled, $isHover);
			},
		);


		// Render Font Dropdown Overlay
		if ($this->propFontDropdownOpen !== "") {
			$cw = $this->width - self::SIDEBAR_W;
			$ddX = $cw - self::PAD - 300;
			$ddW = 300;
			$rowIdx = $this->propFontDropdownOpen === "launcher" ? 4 : 5;
			$contentTop = self::HEADER_H + self::TAB_H + 20;
			$ddY =
				$contentTop +
				($rowIdx + 1) * 60 -
				(int) $this->propScrollOffset;

			$fonts = $this->availableFonts;
			$itemH = 32;
			$ddH = count($fonts) * $itemH;

			// Panel background (Glassy)
			$this->drawRect($ddX, $ddY, $ddW, $ddH, $this->colors["dropdown_bg"], 8);
			$this->drawRect($ddX, $ddY, $ddW, 1, $this->colors["divider"]);

			$curFont =
				$this->propFontDropdownOpen === "launcher"
					? $this->settings["font_launcher"] ?? "Open Sans"
					: $this->settings["font_overlay"] ?? "Consolas";

			foreach ($fonts as $fi => $fname) {
				$iy = $ddY + $fi * $itemH;
				$isActive = $fname === $curFont;
				$isItemHover = $this->propFontDropdownHover === $fi;

				if ($isActive || $isItemHover) {
					$vBg = $isActive ? $this->colors["primary"] : $this->colors["dropdown_hover"];
					$this->drawRect($ddX + 4, $iy + 2, $ddW - 8, $itemH - 4, $vBg, 4);
				}

				$color = $isActive ? $this->colors["button_text"] : ($isItemHover ? $this->colors["text"] : $this->colors["text_dim"]);
				$this->renderText($fname, $ddX + 12, $iy + 22, $color, 1000);
			}
		}
	}

	private function renderPropertiesUpdate($y)
	{
		$cw = $this->width - self::SIDEBAR_W;
		
		// Status Label (If there's an update message, show it prominently)
		if ($this->updateMessage !== "") {
			$this->drawRect(self::PAD, $y, $cw - self::PAD * 2, 40, $this->colors["info_bg"]); // Info box
			$this->renderText($this->updateMessage, self::PAD + 15, $y + 25, $this->colors["primary"], 2000);
			$y += 50;
		}

		$btnRenderer = function($label, $idx) {
			return function($x, $cy, $w, $h) use ($label, $idx) {
				$btnHover = $this->propFieldHover === "btn_" . $idx;
				$btnBg = $btnHover ? $this->colors["button_hover"] : $this->colors["button"];

				// Use 200px width button right-aligned within the 300px field
				// Use 200px width button right-aligned within the 300px field
				$bx = $x + 100;
				$bw = 200;

				if ($idx === 1 && $this->isUpdatingCacert) {
					// Draw a progress bar inside the button
					$this->drawRect($bx, $cy, $bw, 40, [0.08, 0.1, 0.12]);
					$fillW = $bw * ($this->caUpdateProgress / 100);
					if ($fillW > 0) {
						$this->drawRect($bx, $cy, $fillW, 40, $this->colors["status_queue"]);
					}
					
					$progressLabel = "UPDATING... " . floor($this->caUpdateProgress) . "%";
					$txtW = $this->getTextWidth($progressLabel, 1000);
					$this->renderText($progressLabel, $bx + ($bw - $txtW) / 2, $cy + 25, [1, 1, 1], 1000);
				} else {
					$this->drawStyledButton($bx, $cy, $bw, 40, $label, $btnHover, "primary");
				}
			};
		};

		// 1. FoxyClient Update
		$y = $this->renderPropRow(
			0,
			$y,
			"Client Update",
			"Check GitHub for out-standing launcher releases",
			$btnRenderer($this->isCheckingUiUpdate ? "CHECKING..." : ($this->hasUiUpdate ? "INSTALL UPDATE" : "Check For Update"), 0)
		);

		// 2. CA Cert Update
		$y = $this->renderPropRow(
			1,
			$y,
			"CA Certificate Trust",
			"Fetch latest root CA certs from curl.se to fix HTTPS errors",
			$btnRenderer($this->isUpdatingCacert ? "UPDATING..." : "Update CA Certs", 1)
		);
	}

	private function renderPropertiesAbout($y)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$this->drawTexture($this->logoTex, ($cw - 100) / 2, $y + 20, 100, 100);
		$this->renderText(
			"FoxyClient OPTIMIZE",
			($cw - $this->getTextWidth("FoxyClient OPTIMIZE", 2000)) / 2,
			$y + 140,
			$this->colors["primary"],
			2000,
		);
		$this->renderText(
			"Version " . self::VERSION,
			($cw - $this->getTextWidth("Version " . self::VERSION, 1000)) / 2,
			$y + 165,
			$this->colors["text"],
			1000,
		);

		$y += 210;
		$this->renderText(
			"Credits",
			self::PAD,
			$y,
			$this->colors["primary"],
			1000,
		);
		$this->renderText(
			"- Dev: Minosuko",
			self::PAD,
			$y + 25,
			$this->colors["text_dim"],
			1000,
		);
		$this->renderText(
			"- Foundation: PHP-FFI / OpenGL",
			self::PAD,
			$y + 50,
			$this->colors["text_dim"],
			1000,
		);

		$this->renderText(
			"An advanced Minecraft launcher & modpack manager.",
			self::PAD,
			$y + 90,
			$this->colors["text_dim"],
			1000,
		);

		$y += 130;
		$this->renderText("Information & Support", self::PAD, $y, $this->colors["primary"], 1000);
		
		$y += 30;
		$this->renderLink("Donate", "https://ko-fi.com/minosuko", self::PAD + 10, $y, $this->aboutDonateHover);
		$y += 25;
		$this->renderLink("GitHub", "https://github.com/Minosuko/FoxyClient", self::PAD + 10, $y, $this->aboutGithubHover);
		$y += 25;
		$this->renderLink("Website", "https://foxyclient.qzz.io", self::PAD + 10, $y, $this->aboutWebsiteHover);
		$y += 25;
		$this->renderLink("Contact", "https://github.com/Minosuko/FoxyClient/issues", self::PAD + 10, $y, $this->aboutContactHover);
	}

	private function renderLink($label, $url, $x, $y, $isHover)
	{
		$color = $isHover ? $this->colors["primary"] : $this->colors["text_dim"];
		$this->renderText("- $label: ", $x, $y, $this->colors["text"], 1000);
		$tw = $this->getTextWidth("- $label: ", 1000);
		$this->renderText($url, $x + $tw, $y, $color, 1000);
		if ($isHover) {
			$this->drawRect($x + $tw, $y + 2, $this->getTextWidth($url, 1000), 1, $color);
		}
	}

	private function openUrl($url)
	{
		try {
			$this->shell32->ShellExecuteA(null, "open", $url, null, null, 1);
		} catch (\Throwable $e) {
			$this->log("Failed to open URL: $url. Error: " . $e->getMessage(), "ERROR");
		}
	}

	private function renderModsPage()
	{
		$cw = $this->width - self::SIDEBAR_W;

		// Gradient header (Themed Glassy Look)
		$c1 = $this->colors["header_bg"];
		$c2 = $this->colors["bg"];
		
		// If using background texture, add transparency
		if ($this->bgTex) {
			$c1 = [$c1[0], $c1[1], $c1[2], 0.8];
			$c2 = [$c2[0], $c2[1], $c2[2], 0.6];
		}

		$this->drawGradientRect(0, 0, $cw, self::HEADER_H, $c1, $c2);
		$this->drawRect(0, 0, $cw, 2, $this->colors["primary"]); // Subtle top accent
		$this->drawRect(0, self::HEADER_H - 1, $cw, 1, $this->colors["divider"]); // Bottom divider

		$this->renderText(
			"MODPACKS BROWSER",
			self::PAD,
			35,
			$this->colors["primary"],
			2000,
		);

		// Filter Pills Data
		$cleanVer = str_replace(
			["Fabric ", "Forge ", "Quilt ", "NeoForge "],
			"",
			$this->config["minecraft_version"] ?? "1.20.1",
		);
		$loader = $this->config["loader"] ?? "fabric";

		// Define pills: [label, filterKey, displayValue]
		$catLabel = $this->modsFilterCategory ? ($this->modsCategoryLabels[$this->modsFilterCategory] ?? $this->modsFilterCategory) : "All";
		$loaderLabel = $this->modsFilterLoader ? ($this->modsLoaderLabels[$this->modsFilterLoader] ?? ucfirst($this->modsFilterLoader)) : ucfirst($loader);
		$envLabel = $this->modsFilterEnv ? ucfirst($this->modsFilterEnv) : "All";
		$verLabel = $cleanVer;

		$pills = [
			["Category", "category", $catLabel],
			["Loader", "loader", $loaderLabel],
			["Env", "env", $envLabel],
			["Version", "version", $verLabel],
		];

		// Calculate total width to right-align next to search bar
		$pillGap = 6;
		$pillWidths = [];
		$totalW = 0;
		foreach ($pills as $pill) {
			$display = $pill[0] . ": " . $pill[2];
			$tw = $this->getTextWidth($display, 3000) + 20;
			$pillWidths[] = $tw;
			$totalW += $tw;
		}
		$totalW += ($pillGap * (count($pills) - 1));

		// Search bar metrics
		$searchW = 300;
		$searchX = $cw - self::PAD - $searchW;
		
		// Rendering pills
		$pillH = 24;
		$pillY = 23; // Vertically center with search bar (Y=15, H=40 -> center Y=35 -> PillY=35-12)
		$pillX = $searchX - 15 - $totalW; // Align to the left of the search bar

		$pillR = 4;
		$this->modsFilterPillRects = []; // Store pill rects for click handling
		foreach ($pills as $pi => $pill) {
			$key = $pill[1];
			$display = $pill[0] . ": " . $pill[2];
			$tw = $pillWidths[$pi];
			$isOpen = $this->modsFilterDropdown === $key;
			$isActive = ($key === "category" && $this->modsFilterCategory !== "") ||
						($key === "loader" && $this->modsFilterLoader !== "") ||
						($key === "env" && $this->modsFilterEnv !== "");

			// Pill background
			$bg = $isOpen ? $this->colors["primary"] : ($isActive ? $this->colors["pill_active"] : $this->colors["pill_bg"]);
			$this->drawRect($pillX, $pillY, $tw, $pillH, $bg, $pillR);

			// Pill text
			$tc = $isOpen ? [1, 1, 1] : ($isActive ? $this->colors["primary"] : $this->colors["text_dim"]);
			$this->renderText($display, $pillX + 10, $pillY + 17, $tc, 3000);

			// Down arrow indicator
			$arrowX = $pillX + $tw - 12;
			$this->renderText($isOpen ? "▴" : "▾", $arrowX, $pillY + 17, $tc, 3000);

			$this->modsFilterPillRects[$key] = [$pillX, $pillY, $tw, $pillH];
			$pillX += $tw + $pillGap;
		}

		// Search Bar (Glassy)
		$searchW = 300;
		$searchX = $cw - self::PAD - $searchW;
		$this->renderSearchBar(
			$searchX,
			15,
			$searchW,
			40,
			$this->modSearchQuery,
			$this->modSearchFocus,
		);

		$this->renderSubTabs(["Mods", "Modpacks", "Installed"], $this->modpackSubTab, 200);

		$y = self::HEADER_H + self::TAB_H;
		$usableH = $this->height - self::TITLEBAR_H;
		$footerH = $this->getFooterVisibility() ? self::FOOTER_H : 0;
		$h = $usableH - $footerH - $y;

		// Trigger discovery if haven't searched yet AND NOT currently searching
		if ($this->lastModrinthQuery === null && !$this->isSearchingModrinth) {
			$this->searchModrinth("");
		}

		if ($this->modpackSubTab === 2) {
			$this->renderInstalledModpacks();
		} else {
			$this->renderModList();
		}

		// Install progress overlay (Always show if installing a modpack)
		if ($this->isInstallingModpack || (isset($this->modpackInstallProgress) && $this->modpackInstallProgress !== "")) {
			$progY = self::HEADER_H + self::TAB_H;
			$isDone = !$this->isInstallingModpack && !str_starts_with($this->modpackInstallProgress, "Error");
			$isErr = str_starts_with($this->modpackInstallProgress, "Error");
			
			$this->drawRect(self::PAD, $progY + 5, $cw - self::PAD * 2, 34, $this->colors["overlay_bg"], 4);
			
			// Show actual progress bar if parsing succeeds
			if ($this->isInstallingModpack && preg_match('/\[(\d+)\/(\d+)\]/', $this->modpackInstallProgress, $matches)) {
				$done = (int)$matches[1];
				$total = (int)$matches[2];
				if ($total > 0) {
					$pct = $done / $total;
					$barW = ($cw - self::PAD * 2) * $pct;
					$this->drawRect(self::PAD, $progY + 5, $barW, 34, $this->colors["primary"], 4);
				}
			}

			$statusColor = $this->isInstallingModpack ? $this->colors["status_update"] : ($isErr ? $this->colors["status_error"] : $this->colors["status_done"]);
			$this->drawRect(self::PAD, $progY + 5, 3, 34, $statusColor);
			
			$this->renderText(
				strtoupper($this->modpackInstallProgress),
				self::PAD + 15,
				$progY + 28,
				$statusColor,
				3000
			);

			if ($isDone) {
				$this->renderText("CHECK", $cw - self::PAD - 25, $progY + 28, $this->colors["status_done"], 1000);
			}
		}

		if ($this->modsFilterDropdown !== "" || $this->modsFilterDropdownAnim > 0.01) {
			$this->renderModsFilterDropdown();
		}
		if ($this->modsVerDropdownOpen || $this->modsVerDropdownAnim > 0.01) {
			$this->renderModsVersionDropdown();
		}
	}

	private function renderModsFilterDropdown()
	{
		$gl = $this->opengl32;
		$key = $this->modsFilterDropdown;
		
		// Determine pill rect for positioning
		$pillRect = $this->modsFilterPillRects[$key] ?? null;
		if (!$pillRect && $key === "") {
			// Closing animation - use last known key
			foreach ($this->modsFilterPillRects as $k => $r) {
				$pillRect = $r;
				break;
			}
		}
		if (!$pillRect) return;

		$ddX = $pillRect[0];
		$ddY = $pillRect[1] + $pillRect[3] + 4; // Below the pill
		$ddW = 220;

		// Build items list
		$items = [];
		$selected = "";
		if ($key === "category") {
			$items[] = ["", "All Categories"];
			foreach ($this->modsCategories as $cat) {
				$items[] = [$cat, $this->modsCategoryLabels[$cat] ?? $cat];
			}
			$selected = $this->modsFilterCategory;
		} elseif ($key === "loader") {
			$items[] = ["", "All Loaders"];
			foreach ($this->modsLoaderList as $ld) {
				$items[] = [$ld, $this->modsLoaderLabels[$ld] ?? ucfirst($ld)];
			}
			$selected = $this->modsFilterLoader;
		} elseif ($key === "env") {
			$items = [["", "All"], ["client", "Client"], ["server", "Server"]];
			$selected = $this->modsFilterEnv;
			$ddW = 150;
		} elseif ($key === "version") {
			$releaseVersions = [];
			foreach ($this->versions as $v) {
				if (($v["type"] ?? "") === "release") {
					$releaseVersions[] = $v["id"];
				}
			}
			if (empty($releaseVersions)) {
				$releaseVersions = [$this->config["minecraft_version"]];
			}
			usort($releaseVersions, function ($a, $b) {
				return version_compare($b, $a);
			});
			foreach ($releaseVersions as $ver) {
				$items[] = [$ver, $ver];
			}
			$selected = str_replace(
				["Fabric ", "Forge ", "Quilt ", "NeoForge "],
				"",
				$this->config["minecraft_version"] ?? "1.20.1",
			);
		}

		if (empty($items)) return;

		$itemH = 30;
		$maxVisible = min(10, count($items));
		$fullH = $maxVisible * $itemH;
		$ddH = $fullH * $this->modsFilterDropdownAnim;

		// Clamp scroll
		$totalH = count($items) * $itemH;
		$maxScroll = max(0, $totalH - $fullH);
		$this->modsFilterScrollTarget = max(0, min($this->modsFilterScrollTarget, $maxScroll));
		$this->modsFilterScrollOffset += ($this->modsFilterScrollTarget - $this->modsFilterScrollOffset) * 0.3;

		// Scissor clip
		$gl->glEnable(0x0c11);
		$gl->glScissor(
			self::SIDEBAR_W + $ddX,
			$this->height - ($ddY + self::TITLEBAR_H + $ddH),
			$ddW,
			$ddH,
		);

		// Panel background (Glassy)
		$this->drawRect($ddX, $ddY, $ddW, $fullH, $this->colors["dropdown_bg"], 8);
		$this->drawRect($ddX, $ddY, $ddW, 1, $this->colors["divider"]);

		// Render items
		$iy = $ddY - $this->modsFilterScrollOffset;
		foreach ($items as $idx => $item) {
			$itemY = $iy + $idx * $itemH;
			if ($itemY + $itemH < $ddY || $itemY > $ddY + $fullH) {
				continue;
			}

			$isSelected = $item[0] === $selected;
			$isHover = $this->modsFilterHoverIdx === $idx;

			if ($isSelected || $isHover) {
				$ibg = $isSelected ? $this->colors["primary"] : $this->colors["card_hover"];
				$this->drawRect($ddX + 4, $itemY + 2, $ddW - 8, $itemH - 4, $ibg, 4);
			}

			$itc = $isSelected ? [1, 1, 1] : ($isHover ? $this->colors["text"] : $this->colors["text_dim"]);
			$this->renderText($item[1], $ddX + 12, $itemY + 20, $itc, 3000);
		}

		// Scroll indicator
		if ($totalH > $fullH) {
			$barH = max(20, ($fullH / $totalH) * $fullH);
			$barY = $ddY + ($this->modsFilterScrollOffset / $maxScroll) * ($fullH - $barH);
			$this->drawRect($ddX + $ddW - 4, $barY, 3, $barH, [1, 1, 1, 0.2]);
		}

		$gl->glDisable(0x0c11);
	}

	private function renderModsVersionDropdown()
	{
		$isLight = ($this->settings["theme"] ?? "dark") === "light";
		// Existing logic for version dropdown...
		// (I'll refactor this into its own method to keep renderModsPage clean)
		$cw = $this->width - self::SIDEBAR_W;
		$gl = $this->opengl32;
		$ddX = self::PAD;
		$ddY = 70;
		$ddW = 300;

		$releaseVersions = [];
		foreach ($this->versions as $v) {
			if (($v["type"] ?? "") === "release") {
				$releaseVersions[] = $v["id"];
			}
		}
		if (empty($releaseVersions)) {
			$releaseVersions = [$this->config["minecraft_version"]];
		}
		usort($releaseVersions, function ($a, $b) {
			return version_compare($b, $a);
		});

		$maxItems = min(8, count($releaseVersions));
		$fullDDH = $maxItems * 35;
		$ddH = $fullDDH * $this->modsVerDropdownAnim;

		$gl->glEnable(0x0c11); // SCISSOR
		$gl->glScissor(
			self::SIDEBAR_W + $ddX,
			$this->height - ($ddY + self::TITLEBAR_H + $ddH),
			$ddW,
			$ddH,
		);
		$this->drawRect($ddX, $ddY, $ddW, $fullDDH, $this->colors["dropdown_bg"], 8);
		$this->drawRect($ddX, $ddY, $ddW, 1, $this->colors["divider"]);

		$lx = $ddX + 6;
		foreach ($this->modsLoaderOptions as $li => $loaderOpt) {
			$ltw = $this->getTextWidth(ucfirst($loaderOpt), 3000) + 20;
			$isActive = $this->config["loader"] === $loaderOpt;
			$isHover = $this->modsVerHoverIdx === 500 + $li;
			
			$lBg = $isActive ? $this->colors["primary"] : ($isHover ? $this->colors["card_hover"] : [0,0,0,0]);
			$lTextColor = $isActive ? [1, 1, 1] : ($isHover ? $this->colors["text"] : $this->colors["text_dim"]);
			
			if ($lBg !== [0,0,0,0]) {
				$this->drawRect($lx, $ddY + 4, $ltw, 24, $lBg, 4);
			}
			$this->renderText(ucfirst($loaderOpt), $lx + 10, $ddY + 19, $lTextColor, 3000);
			$lx += $ltw + 6;
		}

		$itemY = $ddY + 35 - $this->modsVerScrollOffset;
		foreach ($releaseVersions as $vi => $vId) {
			if ($vi >= count($releaseVersions)) break;

			if ($itemY + 35 > $ddY + 30 && $itemY < $ddY + $fullDDH) {
				$isHover = $this->modsVerHoverIdx === $vi;
				$isSelected = $vId === $this->config["minecraft_version"];
				
				if ($isHover || $isSelected) {
					$vBg = $isSelected ? $this->colors["primary"] : $this->colors["card_hover"];
					$this->drawRect($ddX + 4, $itemY + 2, $ddW - 8, 31, $vBg, 4);
				}

				$vTextColor = $isSelected ? [1,1,1] : ($isHover ? $this->colors["text"] : $this->colors["text_dim"]);
				$this->renderText($vId, $ddX + 12, $itemY + 22, $vTextColor, 2000);
			}
			$itemY += 35;
		}

		$gl->glDisable(0x0c11);
	}

	private function renderTabs()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$y = self::HEADER_H;
		// Tab bar background
		$this->drawRect(0, $y, $cw, self::TAB_H, $this->colors["tab_bg"]);
		// Divider
		$this->drawRect(
			0,
			$y + self::TAB_H - 1,
			$cw,
			1,
			$this->colors["divider"],
		);

		$tabX = self::PAD;
		foreach ($this->tabs as $i => $tab) {
			$tw = strlen($tab["name"]) * 8 + 30;
			$isActive = $i === $this->activeTab;
			$isHover = $i === $this->tabHover;

			if ($isActive) {
				$this->drawRect(
					$tabX,
					$y,
					$tw,
					self::TAB_H,
					$this->colors["tab_active"],
				);
				$this->drawRect(
					$tabX,
					$y + self::TAB_H - 3,
					$tw,
					3,
					$this->colors["primary"],
				);
				$this->renderText(
					$tab["name"],
					$tabX + 15,
					$y + 26,
					$this->colors["text"],
					1000,
				);
			} else {
				if ($isHover) {
					$this->drawRect($tabX, $y, $tw, self::TAB_H, [
						0.11,
						0.11,
						0.12,
					]);
				}
				$this->renderText(
					$tab["name"],
					$tabX + 15,
					$y + 26,
					$this->colors["text_dim"],
					1000,
				);
			}
			$tabX += $tw + 4;
		}

		// Select All / Deselect All button (right side)
		$mods = $this->tabs[$this->activeTab]["mods"];
		$allChecked = true;
		foreach ($mods as $mod) {
			if (!$mod["checked"]) {
				$allChecked = false;
				break;
			}
		}
		$saLabel = $allChecked ? "Deselect" : "Select All";
		$saW = 80;
		$saX = $cw - self::PAD - $saW;
		$saY = $y + 8;
		$saH = self::TAB_H - 16;

		// Button bg
		$saBg = $allChecked ? [0.18, 0.15, 0.12] : [0.12, 0.14, 0.18];
		$this->drawRect($saX, $saY, $saW, $saH, $saBg);
		$saColor = $allChecked
			? $this->colors["primary"]
			: $this->colors["accent"];
		$this->renderText($saLabel, $saX + 8, $saY + 18, $saColor, 3000);
	}

	private function renderScrollbar($y, $h)
	{
		$cw = $this->width - self::SIDEBAR_W;
		
		// Map scrollTarget to a scrollbar
		if ($this->maxScroll > 0) {
			$scrollH = max(30, ($h / ($h + $this->maxScroll)) * $h);
			$scrollY = $y + ($this->scrollOffset / $this->maxScroll) * ($h - $scrollH);
			
			// Background
			$this->drawRect($cw - 8, $y, 6, $h, $this->colors["card"], 3);
			
			// Handle (Themed)
			$this->drawRect($cw - 7, $scrollY, 4, $scrollH, $this->colors["scrollbar"], 2);
		}
	}

	private function drawModCard($mod, $y, $isHover)
	{
		$cw = $this->width - self::SIDEBAR_W;
		$x = self::PAD;
		$w = $cw - self::PAD * 2;
		$h = self::CARD_H;

		// Card Background (Themed)
		$bgColor = $isHover ? $this->colors["card_hover"] : $this->colors["card"];
		$this->drawRect($x, $y, $w, $h, $bgColor, 6);

		// Left accent bar
		if ($mod["enabled"] ?? true) {
			$this->drawRect($x, $y, 4, $h, $this->colors["primary"], 6);
		}

		// Toggle switch for checked state
		$cbX = $x + 10;
		$cbY = $y + (self::CARD_H - 22) / 2;
		$this->drawToggleSwitch($cbX, $cbY, $mod["checked"], $isHover);

		// Mod name
		$stX_val =
			$cw - self::PAD - (strlen($mod["status"] ?? "IDLE") * 7 + 16 + 8);
		$maxNameW = $stX_val - ($cbX + 54) - 10;
		$nameFont = 1000; // Regular 20px
		if ($this->getTextWidth($mod["id"], $nameFont) > $maxNameW) {
			$nameFont = 3000; // Small 15px
		}
		$this->renderText(
			$mod["id"],
			$cbX + 54,
			$y + 28,
			$this->colors["text"],
			$nameFont,
		);

		// Compatibility badge
		$compat = $this->modCompatCache[$mod["id"]] ?? "";
		if ($compat !== "") {
			$nameW = $this->getTextWidth($mod["id"], 1000);
			if ($compat === "compatible") {
				$this->renderText(
					"✓",
					$cbX + 58 + $nameW,
					$y + 28,
					$this->colors["status_done"],
					1000,
				);
			} elseif ($compat === "incompatible") {
				$this->renderText(
					"✗",
					$cbX + 58 + $nameW,
					$y + 28,
					$this->colors["status_error"],
					1000,
				);
			} elseif ($compat === "checking") {
				$this->renderText(
					"...",
					$cbX + 58 + $nameW,
					$y + 28,
					$this->colors["text_dim"],
					1000,
				);
			}
		}
		// Status badge
		if ($mod["status"] !== "idle") {
			$statusColors = [
				"queued" => $this->colors["status_queue"],
				"updating" => $this->colors["status_update"],
				"downloading" => $this->colors["status_update"],
				"done" => $this->colors["status_done"],
				"error" => $this->colors["status_error"],
			];
			$sColor =
				$statusColors[$mod["status"]] ?? $this->colors["text_dim"];
			$statusText = strtoupper($mod["status"]);
			$stLen = strlen($statusText) * 7 + 16;
			$stX = $cw - self::PAD - $stLen - 8; // Relative to content width

			// Badge background
			$this->drawRect($stX, $y + 10, $stLen, 24, [
				$sColor[0] * 0.15,
				$sColor[1] * 0.15,
				$sColor[2] * 0.15,
			]);
			$this->renderText($statusText, $stX + 8, $y + 28, $sColor, 3000);

			// Mini progress bar for downloading state
			if ($mod["status"] === "downloading" && isset($mod["pct"])) {
				$pctW = $stLen * ($mod["pct"] / 100);
				if ($pctW > 0) {
					$this->drawRect(
						$stX,
						$y + 10 + 24,
						$pctW,
						2,
						$this->colors["primary"],
					);
				}
			}
		}
	}

	private function renderModList()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$usableH = $this->height - self::TITLEBAR_H;
		$y = self::HEADER_H + self::TAB_H;

		if ($this->currentPage === self::PAGE_MODS && ($this->modpackSubTab === 1 || $this->modpackSubTab === 2) && ($this->isInstallingModpack || $this->modpackInstallProgress !== "")) {
			$y += 46;
		}

		$showFooter = $this->getFooterVisibility();
		$effectiveFooterH = $showFooter ? self::FOOTER_H : 0;
		$h = $usableH - $effectiveFooterH - $y;
		$listH = $h;

		$scissorY = $effectiveFooterH;

		if ($this->currentPage === self::PAGE_MODS) {
			$alpha = $this->modrinthAnim;
			$slideY = (1.0 - $alpha) * 20;

			if ($this->isSearchingModrinth && empty($this->modrinthSearchResults)) {
				$dots = str_repeat(".", ((int) (microtime(true) * 2)) % 4);
				$this->renderText("SEARCHING MODRINTH" . $dots, $cw / 2 - 100, $y + 60, $this->colors["primary"], 2000);
				$this->renderText("Discovering projects for you", $cw / 2 - 100, $y + 85, $this->colors["text_dim"], 1000);
			} elseif (!empty($this->modrinthError)) {
				$this->renderText("ERROR", $cw / 2 - 30, $y + 50, [1.0, 0.3, 0.3], 2000);
				$this->renderText($this->modrinthError, $cw / 2 - 100, $y + 75, $this->colors["text_dim"], 1000);
			} elseif (empty($this->modrinthSearchResults) && !$this->isSearchingModrinth) {
				$this->renderText("NO RESULTS FOUND", $cw / 2 - 80, $y + 60, $this->colors["text_dim"], 2000);
				$this->renderText("Try a different search term", $cw / 2 - 100, $y + 85, $this->colors["text_dim"], 1000);
			} else {
				$gl = $this->opengl32;
				$gl->glEnable(0x0c11); // SCISSOR
				$gl->glScissor(
					self::SIDEBAR_W,
					$this->height - ($y + self::TITLEBAR_H + $listH),
					$cw,
					$listH,
				);

				$gridX = self::PAD;
				$gridY = $y + 10 - $this->scrollOffset + $slideY;
				$cardW = ($cw - self::PAD * 3) / 2;
				$cardH = 110; // Slightly taller for better spacing
				$gap = 12;

				foreach ($this->modrinthSearchResults as $i => $hit) {
					$col = $i % 2;
					$row = floor($i / 2);
					$itemX = $gridX + $col * ($cardW + $gap);
					$itemY = $gridY + $row * ($cardH + $gap);

					if ($itemY + $cardH > $y && $itemY < $y + $listH) {
						$isHover = $this->hoverModIndex === $i;
						$this->drawSearchResultCard($hit, $itemX, $itemY, $cardW, $cardH, $isHover, $alpha);
					}
				}

				$gl->glDisable(0x0c11);
			}
		} else {
			$mods = $this->tabs[$this->activeTab]["mods"] ?? [];
			$itemY = $y + 10 - $this->scrollOffset;
			$gl = $this->opengl32;
			$gl->glEnable(0x0c11); // SCISSOR
			$gl->glScissor(
				self::SIDEBAR_W,
				$this->height - ($y + self::TITLEBAR_H + $h),
				$cw,
				$h,
			);

			foreach ($mods as $i => $mod) {
				if ($itemY + self::CARD_H > $y && $itemY < $y + $h) {
					$isHover = $this->hoverModIndex === $i;
					$this->drawModCard($mod, $itemY, $isHover);
				}
				$itemY += self::CARD_H + self::CARD_GAP;
			}

			$gl->glDisable(0x0c11);
		}



		// Pagination UI (Outside Scissor, Fixed Window-Relative)
		if (
			$this->currentPage === self::PAGE_MODS &&
			$this->modrinthTotalHits > 20
		) {
			$pgY = $usableH - $effectiveFooterH - 45;
			$pgW = 200;
			$pgX = ($cw - $pgW) / 2;
			$totalPages = ceil($this->modrinthTotalHits / 20);
			$displayPage =
				$this->modPageDebounceTimer > 0
					? $this->modPageTarget
					: $this->modrinthPage;
			$curPage = $displayPage + 1;

			$alpha = $this->modrinthAnim;

			// Prev Button
			$prevHover =
				$this->mouseX >= self::SIDEBAR_W + $pgX &&
				$this->mouseX <= self::SIDEBAR_W + $pgX + 60 &&
				$this->mouseY >= self::TITLEBAR_H + $pgY &&
				$this->mouseY <= self::TITLEBAR_H + $pgY + 30;
			$prevCol =
				$displayPage > 0
					? ($prevHover
						? $this->colors["primary"]
						: $this->colors["card_hover"])
					: $this->colors["tab_bg"];
			$this->drawRect($pgX, $pgY, 60, 30, $prevCol);
			$this->renderText(
				"<",
				$pgX + 23,
				$pgY + 22,
				$this->colors["text"],
				1000,
			);

			// Page Info
			$pgText = "Page $curPage / $totalPages";
			$tw = $this->getTextWidth($pgText, 1000);
			$this->renderText(
				$pgText,
				$pgX + 60 + ($pgW - 120 - $tw) / 2,
				$pgY + 22,
				$this->colors["text"],
				1000,
			);

			// Next Button
			$nextX = $pgX + $pgW - 60;
			$nextHover =
				$this->mouseX >= self::SIDEBAR_W + $nextX &&
				$this->mouseX <= self::SIDEBAR_W + $nextX + 60 &&
				$this->mouseY >= self::TITLEBAR_H + $pgY &&
				$this->mouseY <= self::TITLEBAR_H + $pgY + 30;
			$nextCol =
				$curPage < $totalPages
					? ($nextHover
						? $this->colors["primary"]
						: $this->colors["card_hover"])
					: $this->colors["tab_bg"];
			$this->drawRect($nextX, $pgY, 60, 30, $nextCol);
			$this->renderText(
				">",
				$nextX + 23,
				$pgY + 22,
				$this->colors["text"],
				1000,
			);
		}

		$this->renderScrollbar($y, $listH);
	}

	private function renderInstalledModpacks()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$usableH = $this->height - self::TITLEBAR_H;
		$yBase = self::HEADER_H + self::TAB_H;
		$showFooter = $this->getFooterVisibility();
		$effectiveFooterH = $showFooter ? self::FOOTER_H : 0;
		$h = $usableH - $effectiveFooterH - $yBase;

		$y = $yBase + 10 - $this->scrollOffset;
		if ($this->isInstallingModpack || $this->modpackInstallProgress !== "") {
			$y += 46;
		}

		if (empty($this->installedModpacks)) {
			$this->renderText("No modpacks installed yet.", self::PAD, $y + 20, $this->colors["text_dim"], 1000);
			return;
		}

		$idx = 0;
		$gl = $this->opengl32;
		$gl->glEnable(0x0c11); // SCISSOR
		$gl->glScissor(
			self::SIDEBAR_W,
			$this->height - ($yBase + self::TITLEBAR_H + $h),
			$cw,
			$h,
		);

		foreach ($this->installedModpacks as $slug => $pack) {
			$cardH = 72;
			if ($y + $cardH > $yBase && $y < $yBase + $h) {
				$isHover = $this->modpackUninstallHover === $idx;

				$btnW = 100;
				$btnH = 32;
				$btnX = $cw - self::PAD * 2 - $btnW - 10;
				$btnY2 = $y + ($cardH - $btnH) / 2;

				// Card background
				$this->drawCard(self::PAD, $y, $cw - self::PAD * 2, $cardH, $isHover, false);

				// Icon rendering
				$iconTid = $this->modpackIconCache[$slug] ?? null;
				$iconSize = 48;
				$iconX = self::PAD + 14;
				$iconY = $y + ($cardH - $iconSize) / 2;
				
				if ($iconTid) {
					$this->drawTexture($iconTid, $iconX, $iconY, $iconSize, $iconSize);
				} else {
					$this->drawRect($iconX, $iconY, $iconSize, $iconSize, $this->colors["panel"]);
					$this->renderText("?", $iconX + 18, $iconY + 32, $this->colors["text_dim"], 1000);
				}

				// Modpack name
				$this->renderText($pack["name"] ?? $slug, $iconX + $iconSize + 16, $y + 30, $this->colors["text"], 2000);

				// Info & Badges
				$mcVer = $pack["mc_version"] ?? "?";
				$loader = ucfirst($pack["loader"] ?? "?");
				$fileCount = count($pack["files"] ?? []);
				
				$infoY = $y + 50;
				$infoOffsetX = $iconX + $iconSize + 16;
				
				// Loader Badge
				$twLoader = $this->getTextWidth($loader, 3000) + 12;
				$this->drawRect($infoOffsetX, $infoY - 11, $twLoader, 16, [0,0,0,0.2]);
				$this->renderText($loader, $infoOffsetX + 6, $infoY, $this->colors["primary"], 3000);
				$infoOffsetX += $twLoader + 8;
				
				// MC Version Badge
				$twMc = $this->getTextWidth($mcVer, 3000) + 12;
				$this->drawRect($infoOffsetX, $infoY - 11, $twMc, 16, [0,0,0,0.2]);
				$this->renderText($mcVer, $infoOffsetX + 6, $infoY, $this->colors["status_done"], 3000);
				$infoOffsetX += $twMc + 8;
				
				// Files label
				$this->renderText("$fileCount files", $infoOffsetX, $infoY, $this->colors["text_dim"], 3000);

				// Action Buttons area
				$pBtnW = 80;
				$pBtnX = $cw - self::PAD * 2 - $btnW - 20 - $pBtnW;
				$isPHover = $this->mouseX >= $pBtnX + self::SIDEBAR_W && $this->mouseX <= $pBtnX + self::SIDEBAR_W + $pBtnW &&
							$this->mouseY >= $btnY2 + self::TITLEBAR_H && $this->mouseY <= $btnY2 + self::TITLEBAR_H + $btnH;

				$isDelHover = $this->mouseX >= $btnX + self::SIDEBAR_W && $this->mouseX <= $btnX + self::SIDEBAR_W + $btnW &&
							  $this->mouseY >= self::TITLEBAR_H + $btnY2 && $this->mouseY <= $btnY2 + self::TITLEBAR_H + $btnH;

				$this->drawStyledButton($pBtnX, $btnY2, $pBtnW, $btnH, "PLAY", $isPHover, "success");
				$this->drawStyledButton($btnX, $btnY2, $btnW, $btnH, "UNINSTALL", $isDelHover, "danger");
			}

			$y += $cardH + 8;
			$idx++;
		}
		$gl->glDisable(0x0c11);
		$this->renderScrollbar($yBase, $h);
	}

	private function getFooterVisibility()
	{
		$totalQueued = 0;
		$isUpdating = false;
		foreach ($this->tabs as $tab) {
			foreach ($tab["mods"] as $mod) {
				if (
					in_array($mod["status"] ?? "", [
						"queued",
						"updating",
						"downloading",
						"done",
						"ok",
						"skip",
						"error",
						"failed",
					])
				) {
					$totalQueued++;
					if (
						!in_array($mod["status"], [
							"done",
							"ok",
							"skip",
							"error",
							"failed",
						])
					) {
						$isUpdating = true;
					}
				}
			}
		}

		$isFabric =
			$this->selectedVersion &&
			stripos($this->selectedVersion, "fabric") !== false;
		$activeUpdate = $isUpdating && $totalQueued > 0;

		// In Modpacks tab, we ONLY show footer if an active update is running (Fullpage Immersion)
		return $isUpdating && $totalQueued > 0;
	}

	private function renderFooter()
	{
		$cw = $this->width - self::SIDEBAR_W;
		$usableH = $this->height - self::TITLEBAR_H;
		$y = $usableH - self::FOOTER_H;

		// Count progress
		$totalQueued = 0;
		$completed = 0;
		$isUpdating = false;
		foreach ($this->tabs as $tab) {
			foreach ($tab["mods"] as $mod) {
				if (
					in_array($mod["status"], [
						"queued",
						"updating",
						"downloading",
						"done",
						"ok",
						"skip",
						"error",
						"failed",
					])
				) {
					$totalQueued++;
					if (
						in_array($mod["status"], [
							"done",
							"ok",
							"skip",
							"error",
							"failed",
						])
					) {
						$completed++;
					} else {
						$isUpdating = true;
					}
				}
			}
		}

		if (!($isUpdating && $totalQueued > 0)) {
			return;
		}

		// Footer bg
		$this->drawRect(0, $y, $cw, self::FOOTER_H, $this->colors["panel"]);
		// Top divider
		$this->drawRect(0, $y, $cw, 1, $this->colors["divider"]);

		if ($isUpdating && $totalQueued > 0) {
			$progress = $completed / $totalQueued;

			// Progress bar background
			$barW = 400;
			$barH = 12;
			$barX = ($cw - $barW) / 2;
			$barY = $y + 14;
			$this->drawRect($barX, $barY, $barW, $barH, [0.15, 0.15, 0.17]);

			// Progress fill with gradient effect
			$fillW = $barW * $progress;
			if ($fillW > 0) {
				$this->drawRect(
					$barX,
					$barY,
					$fillW,
					$barH,
					$this->colors["primary"],
				);
				// Bright edge highlight
				$this->drawRect(
					$barX + $fillW - 2,
					$barY,
					2,
					$barH,
					$this->colors["accent"],
				);
			}

			// Progress text
			$pct = (int) ($progress * 100);
			$pctText = "$completed / $totalQueued mods ({$pct}%)";
			$this->renderText(
				$pctText,
				$barX,
				$barY + $barH + 18,
				$this->colors["text_dim"],
				3000,
			);

			// Pulsing status text
			$pulse = sin($this->buttonPulse) * 0.1 + 0.7;
			$this->renderText(
				"Downloading...",
				($cw - 80) / 2,
				$barY + $barH + 42,
				[$pulse, $pulse, 1.0],
				1000,
			);
		}
	}

	private function handleFooterClick($cx, $cy)
	{
		// Only consume clicks if the footer is actually visible
		if (!$this->getFooterVisibility()) {
			return false;
		}

		$usableH = $this->height - self::TITLEBAR_H;
		$fy = $usableH - self::FOOTER_H;
		if ($cy < $fy) {
			return false;
		}

		$cw = $this->width - self::SIDEBAR_W;

		return true; // Click inside footer area consumes the event
	}

	private function renderTitleBar()
	{
		$gl = $this->opengl32;
		// Background
		$this->drawRect(
			0,
			0,
			$this->width,
			self::TITLEBAR_H,
			$this->colors["titlebar_bg"],
		);
		$this->drawRect(
			0,
			self::TITLEBAR_H - 1,
			$this->width,
			1,
			$this->colors["divider"],
		);

		// Brand logo
		$this->drawTexture($this->logoTex, 6, 6, 20, 20);
		$this->renderText(
			"Foxy Client " . self::VERSION,
			32,
			22,
			$this->colors["text"],
			1000,
		);

		// Window Controls
		// Close
		$clsBg = $this->titleCloseHover
			? [0.85, 0.25, 0.25]
			: $this->colors["titlebar_bg"];
		$this->drawRect($this->width - 46, 0, 46, self::TITLEBAR_H, $clsBg);
		$gl->glLineWidth(1.5);
		// Draw thin X icon
		$ix = $this->width - 28;
		$iy = 16;
		$is = 10;
		$this->drawLine($ix, $iy, $ix + $is, $iy + $is, $this->colors["text"]);
		$this->drawLine($ix + $is, $iy, $ix, $iy + $is, $this->colors["text"]);

		// Minimize
		$minBg = $this->titleMinHover
			? $this->colors["card_hover"]
			: $this->colors["titlebar_bg"];
		$this->drawRect($this->width - 92, 0, 46, self::TITLEBAR_H, $minBg);
		// Draw thin _ icon
		$mx = $this->width - 74;
		$my = 21;
		$ms = 10;
		$this->drawRect($mx, $my, $ms, 1, $this->colors["text"]);
	}

	private function performNativeDrag()
	{
		$this->user32->ReleaseCapture();
		// WM_NCLBUTTONDOWN = 0xA1, HTCAPTION = 2
		$this->user32->SendMessageA($this->hwnd, 0xa1, 2, null);
	}

	private function renderJavaModal()
	{
		$gl = $this->opengl32;
		// Dim background
		$this->drawRect(0, 0, $this->width, $this->height, [0, 0, 0, 0.7]);

		$mw = 500;
		$mh = 450;
		$mx = ($this->width - $mw) / 2;
		$my = ($this->height - $mh) / 2;

		// Modal card
		$this->drawRect($mx, $my, $mw, $mh, $this->colors["panel"]);
		$this->drawRect($mx, $my, $mw, 3, $this->colors["primary"]); // Top accent

		$this->renderText(
			"JAVA / JRE CONFIGURATION",
			$mx + 20,
			$my + 40,
			$this->colors["primary"],
			2000,
		);

		$y = $my + 80;

		// Java Path
		$this->renderText(
			"Java Executable Path",
			$mx + 20,
			$y,
			$this->colors["text"],
			1000,
		);
		$this->renderModalTextField(
			$mx + 20,
			$y + 10,
			$mw - 130,
			40,
			"java_path",
			"java",
		);

		// Browse button (ID 98)
		$bx = $mx + $mw - 100;
		$by = $y + 10;
		$bw = 80;
		$bh = 40;
		$isBHover = $this->javaModalHoverIdx === 98;
		$this->drawRect(
			$bx,
			$by,
			$bw,
			$bh,
			$isBHover ? $this->colors["button_hover"] : $this->colors["button"],
		);
		$this->renderText(
			"BROWSE",
			$bx + 10,
			$by + 26,
			$this->colors["button_text"],
			3000,
		);
		$y += 70;

		// Java Arguments
		$this->renderText(
			"Java / JVM Arguments",
			$mx + 20,
			$y,
			$this->colors["text"],
			1000,
		);
		$this->renderModalTextField(
			$mx + 20,
			$y + 10,
			$mw - 40,
			40,
			"java_args",
			"-Xmx2G",
		);
		$y += 70;

		// Minecraft Arguments
		$this->renderText(
			"Minecraft Arguments",
			$mx + 20,
			$y,
			$this->colors["text"],
			1000,
		);
		$this->renderModalTextField(
			$mx + 20,
			$y + 10,
			$mw - 40,
			40,
			"minecraft_args",
			"",
		);
		$y += 70;

		// GC Optimizer Dropdown
		$this->renderText(
			"Improved JVM Arguments",
			$mx + 20,
			$y,
			$this->colors["text"],
			1000,
		);
		$this->renderModalDropdown($mx + 20, $y + 10, $mw - 40, 40);
		$y += 80;

		// Close button
		$btnW = 120;
		$btnH = 36;
		$btnX = $mx + $mw - $btnW - 20;
		$btnY = $my + $mh - $btnH - 20;
		$isHover = $this->javaModalHoverIdx === 99;
		$btnColor = $isHover
			? $this->colors["button_hover"]
			: $this->colors["button"];
		$this->drawRect($btnX, $btnY, $btnW, $btnH, $btnColor);
		$this->renderText(
			"CLOSE",
			$btnX + 35,
			$btnY + 24,
			$this->colors["button_text"],
			3000,
		);
	}

	private function renderModalTextField($x, $y, $w, $h, $key, $placeholder)
	{
		$isActive =
			$this->javaModalActiveField === $key ||
			$this->bgModalActiveField === $key;
		$bgColor = $isActive
			? $this->colors["input_bg_active"]
			: $this->colors["input_bg"];
		$this->drawRect($x, $y, $w, $h, $bgColor);
		$borderColor = $isActive
			? $this->colors["primary"]
			: $this->colors["divider"];
		$this->drawRect($x, $y + $h - 2, $w, 2, $borderColor);

		$val = $this->settings[$key];
		$display = $val . ($isActive ? "_" : "");
		if (empty($val) && !$isActive) {
			$this->renderText(
				$placeholder,
				$x + 10,
				$y + 26,
				$this->colors["text_dim"],
				1000,
			);
		} else {
			if (strlen($display) > 50) {
				$display = "..." . substr($display, -47);
			}
			$this->renderText(
				$display,
				$x + 10,
				$y + 26,
				$this->colors["text"],
				1000,
			);
		}
	}

	private function renderModalDropdown($x, $y, $w, $h)
	{
		$curVal = $this->settings["jvm_optimizer"];
		$label = $this->jvmOptions[$curVal] ?? $curVal;

		$this->drawRect($x, $y, $w, $h, $this->colors["input_bg"]);
		$this->drawRect($x, $y + $h - 2, $w, 2, $this->colors["divider"]);
		$this->renderText(
			$label,
			$x + 10,
			$y + 26,
			$this->colors["text"],
			1000,
		);

		// Arrow
		$this->renderText(
			$this->javaModalDropdownOpen ? "▲" : "▼",
			$x + $w - 25,
			$y + 26,
			$this->colors["primary"],
			3000,
		);

		if (
			$this->javaModalDropdownOpen ||
			$this->javaModalDropdownAnim > 0.01
		) {
			$maxH = count($this->jvmOptions) * 35;
			$ddH = $maxH * $this->javaModalDropdownAnim;
			$dy = $y + $h;
			$gl = $this->opengl32;

			$gl->glEnable(0x0c11); // SCISSOR
			$gl->glScissor($x, $this->height - ($dy + $ddH), $w, $ddH);

			$this->drawRect(
				$x,
				$dy,
				$w,
				$maxH,
				$this->colors["input_bg_active"],
			);

			$idx = 0;
			foreach ($this->jvmOptions as $optKey => $optLabel) {
				$itemY = $dy + $idx * 35;
				$isHover = $this->javaModalHoverIdx === $idx + 10;
				if ($isHover) {
					$this->drawRect(
						$x,
						$itemY,
						$w,
						35,
						$this->colors["sidebar_hover"],
					);
				}
				$color =
					$optKey === $curVal
						? $this->colors["primary"]
						: $this->colors["text"];
				$this->renderText(
					$optLabel,
					$x + 10,
					$itemY + 23,
					$color,
					1000,
				);
				$idx++;
			}
			$gl->glDisable(0x0c11);
		}
	}

	private function computeJavaModalHover($x, $y)
	{
		$this->javaModalHoverIdx = -1;
		$mw = 500;
		$mh = 450;
		$mx = ($this->width - $mw) / 2;
		$my = ($this->height - $mh) / 2;

		// Close button (ID 99)
		$btnW = 120;
		$btnH = 36;
		$btnX = $mx + $mw - $btnW - 20;
		$btnY = $my + $mh - $btnH - 20;
		if (
			$x >= $btnX &&
			$x <= $btnX + $btnW &&
			$y >= $btnY &&
			$y <= $btnY + $btnH
		) {
			$this->javaModalHoverIdx = 99;
		}

		// Browse button (ID 98)
		$yBase = $my + 80 + 10; // Y for the Java Path text field
		$bx = $mx + $mw - 100;
		$by = $yBase;
		$bw = 80;
		$bh = 40;
		if ($x >= $bx && $x <= $bx + $bw && $y >= $by && $y <= $by + $bh) {
			$this->javaModalHoverIdx = 98;
		}

		// Dropdown hover (IDs 10-14)
		if ($this->javaModalDropdownOpen) {
			$dropX = $mx + 20;
			$dropW = $mw - 40;
			$dropY = $my + 80 + 70 * 3 + 10 + 40; // After 3 rows + dropdown header
			if ($x >= $dropX && $x <= $dropX + $dropW) {
				$idx = (int) floor(($y - $dropY) / 35);
				if ($idx >= 0 && $idx < count($this->jvmOptions)) {
					$this->javaModalHoverIdx = $idx + 10;
					return;
				}
			}
		}
	}

	private function handleJavaModalClick($x, $y)
	{
		$mw = 500;
		$mh = 450;
		$mx = ($this->width - $mw) / 2;
		$my = ($this->height - $mh) / 2;

		// Reset active field if clicking outside text boxes
		$oldField = $this->javaModalActiveField;
		$this->javaModalActiveField = "";

		// Close button
		if ($this->javaModalHoverIdx === 99) {
			$this->javaModalOpen = false;
			$this->saveSettings();
		} elseif ($this->javaModalHoverIdx === 98) {
			// Browse for Java
			$ofn = $this->comdlg32->new("OPENFILENAMEA");
			FFI::memset(FFI::addr($ofn), 0, FFI::sizeof($ofn));
			$ofn->lStructSize = FFI::sizeof($ofn);
			$ofn->hwndOwner = $this->hwnd;
			$filter =
				"Java Executable (javaw.exe)\0javaw.exe\0All Files\0*.*\0\0";
			$filterBuf = FFI::new("char[" . strlen($filter) . "]");
			FFI::memcpy($filterBuf, $filter, strlen($filter));
			$ofn->lpstrFilter = FFI::cast("char*", $filterBuf);

			$fileBuf = FFI::new("char[260]");
			FFI::memset($fileBuf, 0, 260);
			$ofn->lpstrFile = FFI::cast("char*", $fileBuf);
			$ofn->nMaxFile = 260;
			$ofn->Flags = 0x00000800 | 0x00000008; // OFN_PATHMUSTEXIST | OFN_NOCHANGEDIR

			if ($this->comdlg32->GetOpenFileNameA(FFI::addr($ofn))) {
				$newPath = FFI::string($fileBuf);
				if ($newPath) {
					$this->settings["java_path"] = $newPath;
					$this->saveSettings();
				}
			}
		}

		// Text fields
		$fields = ["java_path", "java_args", "minecraft_args"];
		$fy = $my + 90;
		foreach ($fields as $field) {
			if (
				$x >= $mx + 20 &&
				$x <= $mx + $mw - 20 &&
				$y >= $fy &&
				$y <= $fy + 40
			) {
				$this->javaModalActiveField = $field;
				return;
			}
			$fy += 70;
		}

		// Dropdown toggle
		if (
			$x >= $mx + 20 &&
			$x <= $mx + $mw - 20 &&
			$y >= $fy &&
			$y <= $fy + 40
		) {
			$this->javaModalDropdownOpen = !$this->javaModalDropdownOpen;
			return;
		}

		// Dropdown selection
		if (
			$this->javaModalDropdownOpen &&
			$this->javaModalHoverIdx >= 10 &&
			$this->javaModalHoverIdx < 15
		) {
			$idx = $this->javaModalHoverIdx - 10;
			$keys = array_keys($this->jvmOptions);
			if (isset($keys[$idx])) {
				$this->settings["jvm_optimizer"] = $keys[$idx];
				$this->javaModalDropdownOpen = false;
				$this->saveSettings();
			}
			return;
		}

		$this->javaModalDropdownOpen = false;
	}

	// ─── Background Modal System ───

	private function renderBgModal()
	{
		// Dim background
		$this->drawRect(0, 0, $this->width, $this->height, [0, 0, 0, 0.7]);

		$mw = 500;
		$mh = 320;
		$mx = ($this->width - $mw) / 2;
		$my = ($this->height - $mh) / 2;

		// Modal card
		$this->drawRect($mx, $my, $mw, $mh, $this->colors["panel"]);
		$this->drawRect($mx, $my, $mw, 3, $this->colors["primary"]); // Top accent

		$this->renderText(
			"BACKGROUND CONFIGURATION",
			$mx + 20,
			$my + 40,
			$this->colors["primary"],
			2000,
		);

		$y = $my + 80;

		// Background File Path
		$this->renderText(
			"Background Image",
			$mx + 20,
			$y,
			$this->colors["text"],
			1000,
		);
		$this->renderModalTextField(
			$mx + 20,
			$y + 10,
			$mw - 130,
			40,
			"bg_file",
			"No background set",
		);

		// Browse button (ID 1)
		$bx = $mx + $mw - 100;
		$by = $y + 10;
		$bw = 80;
		$bh = 40;
		$isBHover = $this->bgModalHoverIdx === 1;
		$this->drawStyledButton($bx, $by, $bw, $bh, "BROWSE", $isBHover, "primary");
		$y += 70;

		// Blur Control
		$this->renderText(
			"Background Blur",
			$mx + 20,
			$y,
			$this->colors["text"],
			1000,
		);
		$blurY = $y + 10;
		$blurVal = $this->settings["bg_blur"] ?? 0;

		// - btn (ID 2)
		$isHoverMinus = $this->bgModalHoverIdx === 2;
		$this->drawStyledButton($mx + 20, $blurY, 50, 40, "-", $isHoverMinus, "secondary");

		// Value display
		$valW = $mw - 180;
		$this->drawRect($mx + 80, $blurY, $valW, 40, $this->colors["bg"]);
		// Blur bar fill
		$fillW = ($blurVal / 10) * $valW;
		$this->drawRect($mx + 80, $blurY, $fillW, 40, [
			$this->colors["primary"][0] * 0.3,
			$this->colors["primary"][1] * 0.3,
			$this->colors["primary"][2] * 0.3,
		]);
		$valText = "$blurVal / 10";
		$tw = $this->getTextWidth($valText, 1000);
		$this->renderText(
			$valText,
			$mx + 80 + ($valW - $tw) / 2,
			$blurY + 26,
			$this->colors["text"],
			1000,
		);

		// + btn (ID 3)
		$isHoverPlus = $this->bgModalHoverIdx === 3;
		$plusX = $mx + $mw - 90;
		$this->drawStyledButton($plusX, $blurY, 50, 40, "+", $isHoverPlus, "secondary");

		// Reset button (ID 5)
		$isRHover = $this->bgModalHoverIdx === 5;
		$this->drawStyledButton($mx + 20, $my + $mh - 56, 160, 36, "RESET TO DEFAULT", $isRHover, "danger");

		// Close button (ID 99)
		$btnW = 120;
		$btnH = 36;
		$btnX = $mx + $mw - $btnW - 20;
		$btnY = $my + $mh - $btnH - 20;
		$isHover = $this->bgModalHoverIdx === 99;
		$this->drawStyledButton($btnX, $btnY, $btnW, $btnH, "CLOSE", $isHover, "primary");
	}

	private function computeBgModalHover($x, $y)
	{
		$this->bgModalHoverIdx = -1;
		$mw = 500;
		$mh = 320;
		$mx = ($this->width - $mw) / 2;
		$my = ($this->height - $mh) / 2;

		// Reset button (ID 5)
		if (
			$x >= $mx + 20 &&
			$x <= $mx + 180 &&
			$y >= $my + $mh - 56 &&
			$y <= $my + $mh - 20
		) {
			$this->bgModalHoverIdx = 5;
			return;
		}

		// Close button (ID 99)
		$btnW = 120;
		$btnH = 36;
		$btnX = $mx + $mw - $btnW - 20;
		$btnY = $my + $mh - $btnH - 20;
		if (
			$x >= $btnX &&
			$x <= $btnX + $btnW &&
			$y >= $btnY &&
			$y <= $btnY + $btnH
		) {
			$this->bgModalHoverIdx = 99;
			return;
		}

		// Browse button (ID 1)
		$yBase = $my + 80 + 10;
		$bx = $mx + $mw - 100;
		$bw = 80;
		$bh = 40;
		if (
			$x >= $bx &&
			$x <= $bx + $bw &&
			$y >= $yBase &&
			$y <= $yBase + $bh
		) {
			$this->bgModalHoverIdx = 1;
			return;
		}

		// Blur - btn (ID 2)
		$blurY = $my + 80 + 70 + 10;
		if (
			$x >= $mx + 20 &&
			$x <= $mx + 70 &&
			$y >= $blurY &&
			$y <= $blurY + 40
		) {
			$this->bgModalHoverIdx = 2;
			return;
		}

		// Blur + btn (ID 3)
		$plusX = $mx + $mw - 90;
		if (
			$x >= $plusX &&
			$x <= $plusX + 50 &&
			$y >= $blurY &&
			$y <= $blurY + 40
		) {
			$this->bgModalHoverIdx = 3;
			return;
		}
	}

	private function handleBgModalClick($x, $y)
	{
		$mw = 500;
		$mh = 320;
		$mx = ($this->width - $mw) / 2;
		$my = ($this->height - $mh) / 2;

		$this->bgModalActiveField = "";

		// Reset button
		if ($this->bgModalHoverIdx === 5) {
			$this->settings["bg_file"] = "";
			$this->settings["bg_blur"] = 0;
			$this->saveSettings();
			$this->loadBackground();
			return;
		}

		// Close button
		if ($this->bgModalHoverIdx === 99) {
			$this->bgModalOpen = false;
			$this->saveSettings();
			$this->loadBackground();
			return;
		}

		// Browse button
		if ($this->bgModalHoverIdx === 1) {
			$ofn = $this->comdlg32->new("OPENFILENAMEA");
			FFI::memset(FFI::addr($ofn), 0, FFI::sizeof($ofn));
			$ofn->lStructSize = FFI::sizeof($ofn);
			$ofn->hwndOwner = $this->hwnd;
			$filter = "Images (*.png;*.jpg)\0*.png;*.jpg\0All Files\0*.*\0\0";
			$filterBuf = FFI::new("char[" . strlen($filter) . "]");
			FFI::memcpy($filterBuf, $filter, strlen($filter));
			$ofn->lpstrFilter = FFI::cast("char*", $filterBuf);

			$fileBuf = FFI::new("char[260]");
			FFI::memset($fileBuf, 0, 260);
			$ofn->lpstrFile = FFI::cast("char*", $fileBuf);
			$ofn->nMaxFile = 260;
			$ofn->Flags = 0x00000800 | 0x00000008;

			if ($this->comdlg32->GetOpenFileNameA(FFI::addr($ofn))) {
				$newPath = FFI::string($fileBuf);
				if ($newPath && file_exists($newPath)) {
					$ext = pathinfo($newPath, PATHINFO_EXTENSION);
					$destName = "cached_bg." . $ext;
					$destPath =
						self::CACHE_DIR . DIRECTORY_SEPARATOR . $destName;

					if (!is_dir(self::CACHE_DIR)) {
						mkdir(self::CACHE_DIR, 0777, true);
					}

					// Clean up any old cached backgrounds with different extensions
					foreach (
						glob(
							self::CACHE_DIR .
								DIRECTORY_SEPARATOR .
								"cached_bg.*",
						)
						as $old
					) {
						unlink($old);
					}

					if (copy($newPath, $destPath)) {
						$this->settings["bg_file"] =
							self::CACHE_DIR . "/" . $destName;
						$this->saveSettings();
						$this->log(
							"Background cached to: " .
								$this->settings["bg_file"],
						);
					} else {
						$this->settings["bg_file"] = $newPath;
						$this->saveSettings();
					}
					$this->loadBackground();
				}
			}
			return;
		}

		// Blur - button
		if ($this->bgModalHoverIdx === 2) {
			$this->settings["bg_blur"] = max(
				0,
				($this->settings["bg_blur"] ?? 0) - 1,
			);
			$this->saveSettings();
			return;
		}

		// Blur + button
		if ($this->bgModalHoverIdx === 3) {
			$this->settings["bg_blur"] = min(
				10,
				($this->settings["bg_blur"] ?? 0) + 1,
			);
			$this->saveSettings();
			return;
		}

		// Text field click (bg_file)
		$fy = $my + 90;
		if (
			$x >= $mx + 20 &&
			$x <= $mx + $mw - 110 &&
			$y >= $fy &&
			$y <= $fy + 40
		) {
			$this->bgModalActiveField = "bg_file";
			return;
		}
	}

	// ─── Drawing primitives ───
	private function drawLine($x1, $y1, $x2, $y2, $color)
	{
		$gl = $this->opengl32;
		$gl->glColor4f($color[0], $color[1], $color[2], $this->globalAlpha);
		$gl->glBegin(0x0001); // GL_LINES
		$gl->glVertex2f($x1, $y1);
		$gl->glVertex2f($x2, $y2);
		$gl->glEnd();
	}

	private function drawRect($x, $y, $w, $h, $color)
	{
		$gl = $this->opengl32;
		$alpha = (count($color) > 3 ? $color[3] : 1.0) * $this->globalAlpha;
		$gl->glColor4f($color[0], $color[1], $color[2], $alpha);
		$gl->glBegin(0x0007);
		$gl->glVertex2f($x, $y);
		$gl->glVertex2f($x + $w, $y);
		$gl->glVertex2f($x + $w, $y + $h);
		$gl->glVertex2f($x, $y + $h);
		$gl->glEnd();
	}

	private function drawGradientRect($x, $y, $w, $h, $color1, $color2)
	{
		$gl = $this->opengl32;
		$a1 = (count($color1) > 3 ? $color1[3] : 1.0) * $this->globalAlpha;
		$a2 = (count($color2) > 3 ? $color2[3] : 1.0) * $this->globalAlpha;
		$gl->glBegin(0x0007);
		$gl->glColor4f($color1[0], $color1[1], $color1[2], $a1);
		$gl->glVertex2f($x, $y);
		$gl->glVertex2f($x + $w, $y);
		$gl->glColor4f($color2[0], $color2[1], $color2[2], $a2);
		$gl->glVertex2f($x + $w, $y + $h);
		$gl->glVertex2f($x, $y + $h);
		$gl->glEnd();
	}

	private function initGDIPlus()
	{
		$gp = $this->gdiplus;
		$input = $gp->new("GdiplusStartupInput");
		$input->version = 1;
		$token = FFI::new("unsigned long long[1]");
		$gp->GdiplusStartup(FFI::addr($token[0]), FFI::addr($input), null);
		$this->gdiplusToken = $token[0];
	}

	private function loadBackground()
	{
		$bgFile = $this->settings["bg_file"] ?? "";
		if (empty($bgFile) || !file_exists($bgFile)) {
			$this->bgTex = null;
			return;
		}
		$blurVal = (int) ($this->settings["bg_blur"] ?? 0);
		$this->bgTex = $this->createTextureFromFile(
			$bgFile,
			$this->bgW,
			$this->bgH,
			$blurVal,
		);
	}

	private function loadLogo()
	{
		$this->logoTex = $this->createTextureFromFile(
			self::DATA_DIR . "/images/FoxyClient-Logo.png",
			$this->logoW,
			$this->logoH,
		);
		$this->mojangTex = $this->createTextureFromFile(
			self::DATA_DIR . "/images/Mojang-Logo.png",
		);
		$this->elybyTex = $this->createTextureFromFile(
			self::DATA_DIR . "/images/Elyby-Logo.png",
		);

		if ($this->logoTex) {
			// Create Icon for Window using GDI+
			$path = self::DATA_DIR . "/images/FoxyClient-Logo.png";
			$widePath =
				mb_convert_encoding($path, "UTF-16LE", "UTF-8") . "\0\0";
			$gp = $this->gdiplus;
			$widePathPtr = $gp->new("wchar_t[" . strlen($widePath) / 2 . "]");
			FFI::memcpy($widePathPtr, $widePath, strlen($widePath));

			$bitmapPtr = FFI::new("void*");
			if (
				$gp->GdipCreateBitmapFromFile(
					$widePathPtr,
					FFI::addr($bitmapPtr),
				) === 0
			) {
				$hIcon = $gp->new("HICON[1]");
				$gp->GdipCreateHICONFromBitmap(
					$bitmapPtr,
					FFI::addr($hIcon[0]),
				);
				if ($hIcon[0]) {
					$lParamIcon = $this->user32->cast("LPARAM[1]", $hIcon)[0];
					$ptrIcon = $this->user32->cast("void*", $lParamIcon);
					$this->user32->SendMessageW(
						$this->hwnd,
						0x0080,
						0,
						$ptrIcon,
					);
					$this->user32->SendMessageW(
						$this->hwnd,
						0x0080,
						1,
						$ptrIcon,
					);
				}
				$gp->GdipDisposeImage($bitmapPtr);
			}
		}
	}

	private function createTextureFromMemory(
		$data,
		&$width = null,
		&$height = null,
	) {
		if (empty($data)) {
			return 0;
		}
		$size = strlen($data);

		$hGlobal = $this->kernel32->GlobalAlloc(2, $size); // GMEM_MOVEABLE
		if ($hGlobal === null) {
			return 0;
		}

		$pLocation = $this->kernel32->GlobalLock($hGlobal);
		FFI::memcpy($pLocation, $data, $size);
		$this->kernel32->GlobalUnlock($hGlobal);

		if (!isset($this->ffibuf["pStreamBuf"])) {
			$this->ffibuf["pStreamBuf"] = $this->ole32->new("IUnknown*[1]");
			$this->ffibuf["bitmapPtr"] = FFI::new("void*");
			$this->ffibuf["u32_2"] = $this->gdiplus->new("UINT[2]");
			$this->ffibuf["rect"] = FFI::new("int[4]");
			$this->ffibuf["bd"] = $this->gdiplus->new("BitmapData");
			$this->ffibuf["texIdBuf"] = $this->opengl32->new("UINT[1]");
		}

		$pStreamBuf = $this->ffibuf["pStreamBuf"];
		$res = $this->ole32->CreateStreamOnHGlobal(
			$hGlobal,
			1,
			FFI::addr($pStreamBuf[0]),
		);
		if ($res !== 0) {
			$this->kernel32->GlobalFree($hGlobal);
			return 0;
		}

		$pStream = $pStreamBuf[0];

		$gp = $this->gdiplus;
		$bitmapPtr = $this->ffibuf["bitmapPtr"];
		$res = $gp->GdipCreateBitmapFromStream(
			$this->ole32->cast("void*", $pStream),
			FFI::addr($bitmapPtr),
		);

		if ($res !== 0) {
			$releaseFunc = $pStream->lpVtbl->Release;
			$releaseFunc($pStream);
			return 0;
		}

		$u32_2 = $this->ffibuf["u32_2"];
		$gp->GdipGetImageWidth($bitmapPtr, FFI::addr($u32_2[0]));
		$gp->GdipGetImageHeight($bitmapPtr, FFI::addr($u32_2[1]));
		if ($width !== null) {
			$width = $u32_2[0];
		}
		if ($height !== null) {
			$height = $u32_2[1];
		}

		$rect = $this->ffibuf["rect"];
		$rect[0] = 0;
		$rect[1] = 0;
		$rect[2] = $u32_2[0];
		$rect[3] = $u32_2[1];

		$bd = $this->ffibuf["bd"];
		$gp->GdipBitmapLockBits(
			$bitmapPtr,
			FFI::addr($rect),
			1 | 2,
			0x26200a,
			FFI::addr($bd),
		);

		$gl = $this->opengl32;
		$texIdBuf = $this->ffibuf["texIdBuf"];
		$gl->glGenTextures(1, FFI::addr($texIdBuf[0]));
		$texId = $texIdBuf[0];

		$gl->glBindTexture(0x0de1, $texId);
		$gl->glTexParameteri(0x0de1, 0x2801, 0x2703); // GL_TEXTURE_MIN_FILTER = GL_LINEAR_MIPMAP_LINEAR
		$gl->glTexParameteri(0x0de1, 0x2800, 0x2601); // GL_TEXTURE_MAG_FILTER = GL_LINEAR
		$gl->glTexParameteri(0x0de1, 0x8191, 1); // GL_GENERATE_MIPMAP = GL_TRUE
		$gl->glTexParameteri(0x0de1, 0x2802, 0x2901);
		$gl->glTexParameteri(0x0de1, 0x2803, 0x2901);
		$gl->glTexImage2D(
			0x0de1,
			0,
			0x1908,
			$u32_2[0],
			$u32_2[1],
			0,
			0x80e1,
			0x1401,
			$bd->scan0,
		);

		$gp->GdipBitmapUnlockBits($bitmapPtr, FFI::addr($bd));
		$gp->GdipDisposeImage($bitmapPtr);

		// Release the stream (fDeleteOnRelease handles the HGLOBAL)
		$releaseFunc = $pStream->lpVtbl->Release;
		$releaseFunc($pStream);

		$this->log("Loaded texture from memory ({$u32_2[0]}x{$u32_2[1]})");
		return $texId;
	}

	private function createTextureFromFile(
		$path,
		&$width = null,
		&$height = null,
		$blurRadius = 0,
	) {
		if (!file_exists($path)) {
			return 0;
		}

		$gp = $this->gdiplus;
		// GDI+ needs UTF-16 path
		$widePath = mb_convert_encoding($path, "UTF-16LE", "UTF-8") . "\0\0";
		$widePathPtr = $gp->new("wchar_t[" . strlen($widePath) / 2 . "]");
		FFI::memcpy($widePathPtr, $widePath, strlen($widePath));

		$bitmapPtr = FFI::new("void*");
		$res = $gp->GdipCreateBitmapFromFile(
			$widePathPtr,
			FFI::addr($bitmapPtr),
		);
		if ($res !== 0) {
			return 0;
		}

		if ($blurRadius > 0) {
			$effectPtr = FFI::new("void*");
			// BlurEffectGuid = {633C80A4-1831-4A76-8C5E-20887FD34F18}
			$guid = $gp->new("GUID");
			$guidData = pack(
				"C*",
				0xa4,
				0x80,
				0x3c,
				0x63,
				0x31,
				0x18,
				0x76,
				0x4a,
				0x8c,
				0x5e,
				0x20,
				0x88,
				0x7f,
				0xd3,
				0x4f,
				0x18,
			);
			FFI::memcpy($guid->data, $guidData, 16);

			$createRes = $gp->GdipCreateEffect(
				FFI::addr($guid),
				FFI::addr($effectPtr),
			);
			if ($createRes === 0) {
				$params = $gp->new("BlurParams");
				$params->radius = (float) ($blurRadius * 3.0); // Scalar for visual parity
				$params->expandEdge = 0;

				$setRes = $gp->GdipSetEffectParameters(
					$effectPtr,
					FFI::addr($params),
					FFI::sizeof($params),
				);
				$applyRes = $gp->GdipBitmapApplyEffect(
					$bitmapPtr,
					$effectPtr,
					null,
					0,
					null,
					null,
				);
				$this->log(
					"Blur applied (Native): radius=" .
						$blurRadius * 3 .
						" result=$applyRes",
				);
				$gp->GdipDeleteEffect($effectPtr);
			} else {
				// Fallback: Scale-down Blur (Very high quality and fast)
				$w = $gp->new("UINT[1]");
				$h = $gp->new("UINT[1]");
				$gp->GdipGetImageWidth($bitmapPtr, FFI::addr($w[0]));
				$gp->GdipGetImageHeight($bitmapPtr, FFI::addr($h[0]));

				$origW = $w[0];
				$origH = $h[0];

				// Fix: Scale should INCREASE with blurRadius to make the intermediate image SMALLER (more blurry)
				// For blur level 10, scale will be ~20x, resulting in a very smooth blur.
				$scale = 1.0 + $blurRadius * 2.0;
				$smallW = (int) max(8, $origW / $scale);
				$smallH = (int) max(8, $origH / $scale);

				$smallBitmap = FFI::new("void*");
				// PixelFormat32bppARGB = 0x26200A
				if (
					$gp->GdipCreateBitmapFromScan0(
						$smallW,
						$smallH,
						0,
						0x26200a,
						null,
						FFI::addr($smallBitmap),
					) === 0
				) {
					$graphics = FFI::new("void*");
					$gp->GdipGetImageGraphicsContext(
						$smallBitmap,
						FFI::addr($graphics),
					);
					$gp->GdipSetInterpolationMode($graphics, 7); // HighQualityBicubic
					$gp->GdipDrawImageRectRectI(
						$graphics,
						$bitmapPtr,
						0,
						0,
						$smallW,
						$smallH,
						0,
						0,
						$origW,
						$origH,
						2,
						null,
						null,
						null,
					);
					$gp->GdipDeleteGraphics($graphics);

					// Draw back to a new full-size bitmap for final texture
					$finalBitmap = FFI::new("void*");
					if (
						$gp->GdipCreateBitmapFromScan0(
							$origW,
							$origH,
							0,
							0x26200a,
							null,
							FFI::addr($finalBitmap),
						) === 0
					) {
						$fGraphics = FFI::new("void*");
						$gp->GdipGetImageGraphicsContext(
							$finalBitmap,
							FFI::addr($fGraphics),
						);
						$gp->GdipSetInterpolationMode($fGraphics, 7);
						$gp->GdipDrawImageRectRectI(
							$fGraphics,
							$smallBitmap,
							0,
							0,
							$origW,
							$origH,
							0,
							0,
							$smallW,
							$smallH,
							2,
							null,
							null,
							null,
						);
						$gp->GdipDeleteGraphics($fGraphics);

						$gp->GdipDisposeImage($bitmapPtr);
						$gp->GdipDisposeImage($smallBitmap);
						$bitmapPtr = $finalBitmap;
						$this->log(
							"Blur applied (Fallback): radius=$blurRadius via scale-down ($smallW x $smallH)",
						);
					} else {
						$gp->GdipDisposeImage($smallBitmap);
					}
				}
			}
		}

		$w = $gp->new("UINT[1]");
		$h = $gp->new("UINT[1]");
		$gp->GdipGetImageWidth($bitmapPtr, FFI::addr($w[0]));
		$gp->GdipGetImageHeight($bitmapPtr, FFI::addr($h[0]));
		if ($width !== null) {
			$width = $w[0];
		}
		if ($height !== null) {
			$height = $h[0];
		}

		// Lock bits for RGBA
		$rect = FFI::new("int[4]");
		$rect[0] = 0;
		$rect[1] = 0;
		$rect[2] = $w[0];
		$rect[3] = $h[0];

		$bd = $gp->new("BitmapData");
		// PixelFormat32bppARGB = 0x26200A
		$gp->GdipBitmapLockBits(
			$bitmapPtr,
			FFI::addr($rect),
			1 | 2,
			0x26200a,
			FFI::addr($bd),
		);

		$gl = $this->opengl32;
		$texIdBuf = $gl->new("UINT[1]");
		$gl->glGenTextures(1, FFI::addr($texIdBuf[0]));
		$texId = $texIdBuf[0];

		$gl->glBindTexture(0x0de1, $texId);
		$gl->glTexParameteri(0x0de1, 0x2801, 0x2703); // GL_TEXTURE_MIN_FILTER = GL_LINEAR_MIPMAP_LINEAR
		$gl->glTexParameteri(0x0de1, 0x2800, 0x2601); // GL_TEXTURE_MAG_FILTER = GL_LINEAR
		$gl->glTexParameteri(0x0de1, 0x8191, 1); // GL_GENERATE_MIPMAP = GL_TRUE

		// Enable anti-aliasing (smooth edges)
		$gl->glTexParameteri(0x0de1, 0x2802, 0x2901); // REPEAT
		$gl->glTexParameteri(0x0de1, 0x2803, 0x2901); // REPEAT

		// 0x1908=GL_RGBA, 0x80E1=GL_BGRA
		$gl->glTexImage2D(
			0x0de1,
			0,
			0x1908,
			$w[0],
			$h[0],
			0,
			0x80e1,
			0x1401,
			$bd->scan0,
		);

		$gp->GdipBitmapUnlockBits($bitmapPtr, FFI::addr($bd));
		$gp->GdipDisposeImage($bitmapPtr);

		$this->log("Loaded texture: $path ({$w[0]}x{$h[0]})");

		return $texId;
	}

	private function drawTexture(
		$tex,
		$x,
		$y,
		$w,
		$h,
		$color = [1, 1, 1, 1],
		$uvs = [0, 0, 1, 1],
	) {
		if (!$tex) {
			return;
		}
		$gl = $this->opengl32;
		$gl->glEnable(0x0de1);
		$gl->glBindTexture(0x0de1, $tex);
		$alpha = (count($color) > 3 ? $color[3] : 1.0) * $this->globalAlpha;
		$gl->glColor4f($color[0], $color[1], $color[2], $alpha);
		$gl->glBegin(0x0007);
		$gl->glTexCoord2f($uvs[0], $uvs[1]);
		$gl->glVertex2f($x, $y);
		$gl->glTexCoord2f($uvs[2], $uvs[1]);
		$gl->glVertex2f($x + $w, $y);
		$gl->glTexCoord2f($uvs[2], $uvs[3]);
		$gl->glVertex2f($x + $w, $y + $h);
		$gl->glTexCoord2f($uvs[0], $uvs[3]);
		$gl->glVertex2f($x, $y + $h);
		$gl->glEnd();
		$gl->glDisable(0x0de1);
	}

	private function deleteDirectory($dir)
	{
		if (!is_dir($dir)) {
			return;
		}
		$files = array_diff(scandir($dir), [".", ".."]);
		foreach ($files as $file) {
			$path = $dir . DIRECTORY_SEPARATOR . $file;
			is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
		}
		return rmdir($dir);
	}

	private function cleanup()
	{
		// 1. Absolute Priority: Nuclear Exit (Unblockable OS-level Termination)
		if ($this->kernel32) {
			try {
				fwrite(STDOUT, "[" . date("Y-m-d H:i:s") . "] [INFO] FoxyClient: Ultimate Force Exit triggered.\n");
				$this->kernel32->TerminateProcess($this->kernel32->GetCurrentProcess(), 0);
			} catch (\Throwable $e) {}
		}

		// 2. Failsafe: Standard cleanup (only runs if TerminateProcess somehow fails)
		try {
			if ($this->discord) @$this->discord->close();
			if ($this->gdiplusToken !== null) @$this->gdiplus->GdiplusShutdown($this->gdiplusToken);
			@$this->opengl32->wglMakeCurrent(null, null);
			if ($this->hglrc) @$this->opengl32->wglDeleteContext($this->hglrc);
			if ($this->hwnd) {
				@$this->user32->ReleaseDC($this->hwnd, $this->hdc);
				@$this->user32->DestroyWindow($this->hwnd);
			}
		} catch (\Throwable $e) {}

		exit(0);
	}

	private function purgeIconCache($currentPage = -1)
	{
		$targetPage = $currentPage !== -1 ? $currentPage : $this->modrinthPage;
		// Keep a wider range of pages for instant back/forth switching
		$keepPages = range($targetPage - 5, $targetPage + 4);

		// 1. Purge Result Cache strictly
		foreach ($this->modrinthResultCache as $p => $hits) {
			if (!in_array($p, $keepPages)) {
				unset($this->modrinthResultCache[$p]);
			}
		}

		if (empty($this->modIconCache)) {
			return;
		}

		// 2. Determine which Icon IDs to keep
		$keepIds = [];
		foreach ($keepPages as $p) {
			if ($p >= 0 && isset($this->modrinthResultCache[$p])) {
				foreach ($this->modrinthResultCache[$p] as $hit) {
					if (isset($hit["project_id"])) {
						$keepIds[$hit["project_id"]] = true;
					}
				}
			}
		}

		// Also keep currently visible hits (safety during transitions)
		foreach ($this->modrinthSearchResults as $hit) {
			if (isset($hit["project_id"])) {
				$keepIds[$hit["project_id"]] = true;
			}
		}

		// 3. Delete textures not in the keep list
		$toDelete = [];
		foreach ($this->modIconCache as $id => $tid) {
			if (!isset($keepIds[$id])) {
				$toDelete[] = $tid;
				unset($this->modIconCache[$id]);
				unset($this->modIconLastUse[$id]);
				unset($this->modIconAlpha[$id]);
			}
		}

		if (!empty($toDelete)) {
			$count = count($toDelete);
			$textures = $this->opengl32->new("UINT[$count]");
			foreach ($toDelete as $i => $tid) {
				$textures[$i] = $tid;
			}
			$this->opengl32->glDeleteTextures($count, $textures);
			$this->log(
				"Purged $count mod icon textures from memory (Strict sliding window cleanup).",
			);
		}
	}
	private function hasActiveBackgroundTasks()
	{
		return $this->isSearchingModrinth ||
			$this->modrinthChannel !== null ||
			$this->iconDownloadChannel !== null ||
			!empty($this->modDownloadChannels) ||
			$this->vManifestChannel !== null ||
			$this->assetChannel !== null ||
			$this->compatChannel !== null ||
			$this->gameProcess !== null ||
			$this->isUpdatingCacert ||
			$this->isCheckingUiUpdate ||
			$this->isCheckingCompat ||
			$this->modSearchDebounceTimer > 0 ||
			$this->modPageDebounceTimer > 0 ||
			$this->assetMessage === "GAME RUNNING" ||
			!empty($this->httpPending) ||
			!empty($this->pendingFutures) ||
			$this->process !== null ||
			$this->assetProcess !== null ||
			$this->modrinthProcess !== null ||
			!empty($this->modDownloadRuntimes) ||
			$this->modpackInstallProcess !== null ||
			$this->iconDownloadProcess !== null ||
			$this->vManifestProcess !== null ||
			$this->isInstallingFoxyMod ||
			$this->foxyModInstallProcess !== null;
	}

	private function getAbsolutePath($path)
	{
		if (empty($path)) {
			return __DIR__;
		}
		if (preg_match("/^[a-zA-Z]:\\\\|^[a-zA-Z]:\/|^\/|^\\\\/", $path)) {
			return $path;
		}
		return __DIR__ . DIRECTORY_SEPARATOR . $path;
	}

	private function searchModrinth(
		$query = null,
		$page = 0,
		$isPrefetch = false,
	) {
		$query = $query ?? $this->modSearchQuery;
		$currentMC = str_replace(
			["Fabric ", "Forge ", "Quilt ", "NeoForge "],
			"",
			$this->config["minecraft_version"] ?? "1.20.1",
		);
		$currentLoader = $this->modsFilterLoader !== "" ? $this->modsFilterLoader : ($this->config["loader"] ?? "fabric");
		$currentCategory = $this->modsFilterCategory;
		$currentEnv = $this->modsFilterEnv;

		// Detect full context change and reset cache if needed
		$contextChanged =
			$query !== $this->lastModrinthQuery ||
			$this->modpackSubTab !== $this->lastModrinthSubTab ||
			$currentMC !== $this->lastModrinthMCVer ||
			$currentLoader !== $this->lastModrinthLoader ||
			$currentCategory !== $this->lastModrinthCategory ||
			$currentEnv !== $this->lastModrinthEnv;

		if ($contextChanged) {
			$this->log(
				"Search context changed [Q:'{$this->lastModrinthQuery}'->'$query', T:'{$this->lastModrinthSubTab}'->'{$this->modpackSubTab}', V:'{$this->lastModrinthMCVer}'->'$currentMC', L:'{$this->lastModrinthLoader}'->'$currentLoader', C:'{$this->lastModrinthCategory}'->'$currentCategory', E:'{$this->lastModrinthEnv}'->'$currentEnv']. Clearing cache.",
			);
			$this->modrinthResultCache = [];
			$this->modrinthSearchResults = [];
			$this->modrinthTotalHits = 0;
			$this->lastModrinthQuery = $query;
			$this->lastModrinthSubTab = $this->modpackSubTab;
			$this->lastModrinthMCVer = $currentMC;
			$this->lastModrinthLoader = $currentLoader;
			$this->lastModrinthCategory = $currentCategory;
			$this->lastModrinthEnv = $currentEnv;
			$this->modSearchQuery = $query; // Sync if called externally
		}

		if (!$isPrefetch) {
			$this->purgeIconCache($page);
		}

		// 1. Cache Check
		if (!$isPrefetch && isset($this->modrinthResultCache[$page])) {
			$this->log("Cache hit for Modrinth page $page.");
			$this->modrinthSearchResults = $this->modrinthResultCache[$page];
			$this->modrinthPage = $page;
			$this->isSearchingModrinth = false;
			$this->needsRedraw = true;
			// Prefetch happens via results_finished handler, not here
			return;
		}

		// 2. Concurrency Checks & Promotion
		// If we are already searching or prefetching SOMETHING
		if ($this->modrinthChannel) {
			if (
				!$isPrefetch &&
				$this->isPrefetching &&
				$this->modrinthPrefetchPage === $page
			) {
				// PROMOTION: User requested the page we are currently prefetching!
				// Just mark as searching; pollProcess will handle the rest.
				$this->log(
					"Promoting prefetch task for page $page to main search.",
				);
				$this->isSearchingModrinth = true;
				$this->isPrefetching = false; // Effectively transition to main search
				return;
			}

			if (!$isPrefetch) {
				// USER CLICKED SOMETHING NEW - Prioritize!
				// Detach the old channel (it will still finish but be ignored by property check)
				$this->log(
					"User requested new search/page while busy. Detaching old channel for responsiveness.",
				);
				$this->modrinthChannel = null;
				$this->isSearchingModrinth = false;
				$this->isPrefetching = false;
			} else {
				// Block prefetch if something is already running
				return;
			}
		}

		if ($isPrefetch) {
			$this->isPrefetching = true;
			$this->modrinthPrefetchPage = $page;
			$this->log("Prefetching Modrinth page $page...");
		} else {
			$this->isSearchingModrinth = true;
			$this->modrinthPrefetchPage = -1;
		}

		$this->modrinthError = "";
		if (!$isPrefetch) {
			$this->modrinthPage = $page;
			$this->modrinthAnim = 0.0; // Reset animation on new search
			
			// Also ensure modpack icons are checked when browser is used
			$this->checkModpackIcons();
		}

		$this->modrinthChannel = new \parallel\Channel();
		// Fresh runtime each time - a persistent runtime BLOCKS run() if old task is still active
		$this->modrinthProcess = new \parallel\Runtime();

		$facets =
			$this->modpackSubTab === 0
				? '["project_type:mod"]'
				: '["project_type:modpack"]';
		$version = $this->config["minecraft_version"] ?? "1.20.1";
		$loader = $this->modsFilterLoader !== "" ? $this->modsFilterLoader : ($this->config["loader"] ?? "fabric");
		$cleanVer = str_replace(
			["Fabric ", "Forge ", "Quilt ", "NeoForge "],
			"",
			$version,
		);

		// Build facet groups
		$facetGroups = [];
		$facetGroups[] = '["versions:' . $cleanVer . '"]';
		$facetGroups[] = '["categories:' . $loader . '"]';
		$facetGroups[] = $facets; // project_type

		if (!empty($this->modsFilterCategory)) {
			$facetGroups[] = '["categories:' . $this->modsFilterCategory . '"]';
		}
		if (!empty($this->modsFilterEnv)) {
			// client_side:required or server_side:required
			// Note: 'required' is generally better for filtering than 'optional' when users explicitly pick an env
			$facetGroups[] = '["' . $this->modsFilterEnv . '_side:required","' . $this->modsFilterEnv . '_side:optional"]';
		}

		$offset = $page * 20;
		$url =
			"https://api.modrinth.com/v2/search?query=" .
			urlencode($query) .
			"&facets=" .
			urlencode('[' . implode(',', $facetGroups) . ']') .
			"&limit=20&offset=$offset";

		$this->log(
			"Search Modrinth: ver=$cleanVer, loader=$loader, cat={$this->modsFilterCategory}, env={$this->modsFilterEnv}, query='$query'",
		);
		$this->log("URL: $url");

		$cacert = self::CACERT;
		// Push old Future to pending array to prevent blocking destructor
		if ($this->modrinthFuture) {
			$this->pendingFutures[] = $this->modrinthFuture;
		}
		$this->modrinthFuture = $this->modrinthProcess->run(
			function (
				\parallel\Channel $ch,
				$url,
				$ver,
				$cacert,
				$page,
				$isPrefetch,
				$query,
			) {
				$curl = curl_init($url);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($curl, CURLOPT_USERAGENT, "FoxyClient/" . $ver);
				if (file_exists($cacert)) {
					curl_setopt($curl, CURLOPT_CAINFO, $cacert);
				}
				$data = curl_exec($curl);
				$error = curl_error($curl);
				curl_close($curl);

				if ($data) {
					$ch->send([
						"type" => "results",
						"page" => $page,
						"query" => $query,
						"isPrefetch" => $isPrefetch,
						"data" => json_decode($data, true),
					]);
				} else {
					$ch->send([
						"type" => "error",
						"message" => "Modrinth error: " . $error,
					]);
				}
				$ch->send(["type" => "results_finished"]);
				$ch->close();
			},
			[
				$this->modrinthChannel,
				$url,
				self::VERSION,
				$cacert,
				$page,
				$isPrefetch,
				$query,
			],
		);

		$this->pollEvents->addChannel($this->modrinthChannel);
	}

	private function installModrinthProject(
		$projectId,
		$projectType,
		$projectName,
	) {
		// Route modpack installs to the dedicated modpack installer
		if ($projectType === "modpack") {
			$this->installModpack($projectId, $projectName);
			return;
		}

		if (isset($this->modDownloadChannels[$projectId])) {
			$this->log("Download for $projectName ($projectId) is already in progress.", "WARN");
			return;
		}

		$modDownloadChannel = new \parallel\Channel();
		$this->modDownloadChannels[$projectId] = $modDownloadChannel;
		$this->channelToModId[(string)$modDownloadChannel] = $projectId;
		$this->modDownloadRuntimes[$projectId] = new \parallel\Runtime();
		$this->modDownloadProgresses[$projectId] = 0.0;

		$version = $this->config["minecraft_version"] ?? "1.20.1";
		$loader = $this->config["loader"] ?? "fabric";
		$cleanVer = str_replace(
			["Fabric ", "Forge ", "Quilt ", "NeoForge "],
			"",
			$version,
		);

		$gameDir = $this->getAbsolutePath($this->settings["game_dir"]);
		// Determine target path based on type
		$targetDir =
			$projectType === "modpack"
				? $gameDir . DIRECTORY_SEPARATOR . "modpacks"
				: $gameDir . DIRECTORY_SEPARATOR . "mods";

		if (!is_dir($targetDir)) {
			@mkdir($targetDir, 0777, true);
		}

		$this->log(
			"Fetching versions for $projectName ($projectId) - Loader: $loader, Ver: $cleanVer...",
		);

		$cacert = self::CACERT;
		$this->modDownloadFutures[$projectId] = $this->modDownloadRuntimes[$projectId]->run(
			function (
				\parallel\Channel $ch,
				$pid,
				$ptype,
				$pname,
				$mcver,
				$loader,
				$targetDir,
				$cacert,
			) {
				// Step 1: Query for compatible versions
				$params = [
					"loaders" => json_encode([$loader]),
					"game_versions" => json_encode([$mcver]),
				];
				$url = "https://api.modrinth.com/v2/project/$pid/version?" . http_build_query($params);

				$curl = curl_init($url);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($curl, CURLOPT_USERAGENT, "FoxyClient/Downloader");
				curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
				curl_setopt($curl, CURLOPT_TIMEOUT, 30);
				if (file_exists($cacert)) {
					curl_setopt($curl, CURLOPT_CAINFO, $cacert);
				}

				$response = curl_exec($curl);
				$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
				curl_close($curl);

				if (!$response || $httpCode !== 200) {
					$ch->send(
						json_encode([
							"type" => "error",
							"message" => "Failed to fetch versions for $pname (HTTP $httpCode)",
						]),
					);
					return;
				}

				$versions = json_decode($response, true);
				if (empty($versions)) {
					$ch->send(
						json_encode([
							"type" => "error",
							"message" => "No compatible versions found for $pname on $mcver $loader.",
						]),
					);
					return;
				}

				$targetFile = null;
				$targetUrl = "";
				$targetFilename = "";

				foreach ($versions as $v) {
					if (isset($v["files"]) && is_array($v["files"])) {
						foreach ($v["files"] as $file) {
							if ($file["primary"] || empty($targetUrl)) {
								$targetUrl = $file["url"];
								$targetFilename = $file["filename"];
							}
						}
						if ($targetUrl) {
							break;
						} // Found latest valid release
					}
				}

				if (!$targetUrl) {
					$ch->send(
						json_encode([
							"type" => "error",
							"message" => "No downloadable files found for $pname.",
						]),
					);
					return;
				}

				// Step 1.5: Delete old versions of this mod (only for mods, not modpacks)
				if ($ptype !== "modpack") {
					// Get project slug for old version matching
					$projUrl = "https://api.modrinth.com/v2/project/$pid";
					$pCurl = curl_init($projUrl);
					curl_setopt($pCurl, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($pCurl, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($pCurl, CURLOPT_USERAGENT, "FoxyClient/Downloader");
					if (file_exists($cacert)) {
						curl_setopt($pCurl, CURLOPT_CAINFO, $cacert);
					}
					$projResponse = curl_exec($pCurl);
					curl_close($pCurl);
					$projData = $projResponse ? json_decode($projResponse, true) : null;
					$slug = $projData["slug"] ?? $pid;
					$slugLower = strtolower($slug);
					$newFileLower = strtolower($targetFilename);

					foreach (scandir($targetDir) as $existingFile) {
						if ($existingFile === "." || $existingFile === "..") continue;
						$existingLower = strtolower($existingFile);
						if (!str_ends_with($existingLower, ".jar")) continue;
						if ($existingLower === $newFileLower) continue;
						if (
							str_starts_with($existingLower, $slugLower . "-") ||
							str_starts_with($existingLower, $slugLower . "_") ||
							$existingLower === $slugLower . ".jar"
						) {
							@unlink($targetDir . DIRECTORY_SEPARATOR . $existingFile);
							$ch->send(
								json_encode([
									"type" => "progress",
									"message" => "Removed old version: $existingFile",
								]),
							);
						}
					}
				}

				// Step 2: Download file
				$ch->send(
					json_encode([
						"type" => "progress",
						"message" => "Downloading $targetFilename...",
					]),
				);

				$savePath = $targetDir . DIRECTORY_SEPARATOR . $targetFilename;
				$out = @fopen($savePath, "wb");
				if (!$out) {
					$ch->send(
						json_encode([
							"type" => "error",
							"message" => "Failed to open target file for writing: $savePath",
						]),
					);
					return;
				}

				$dCurl = curl_init($targetUrl);
				curl_setopt($dCurl, CURLOPT_FILE, $out);
				curl_setopt($dCurl, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($dCurl, CURLOPT_USERAGENT, "FoxyClient/Downloader");
				curl_setopt($dCurl, CURLOPT_CONNECTTIMEOUT, 10);
				curl_setopt($dCurl, CURLOPT_TIMEOUT, 60);
				curl_setopt($dCurl, CURLOPT_NOPROGRESS, false);
				curl_setopt($dCurl, CURLOPT_PROGRESSFUNCTION, function($handle, $dlSize, $dlCurrent, $ulSize, $ulCurrent) use ($ch) {
					if ($dlSize > 0) {
						$pct = ($dlCurrent / $dlSize) * 100;
						$ch->send(json_encode([
							"type" => "progress_pct",
							"pct" => $pct
						]));
					}
				});
				if (file_exists($cacert)) {
					curl_setopt($dCurl, CURLOPT_CAINFO, $cacert);
				}

				$success = curl_exec($dCurl);
				$dHttpCode = curl_getinfo($dCurl, CURLINFO_HTTP_CODE);
				$dErr = curl_error($dCurl);
				curl_close($dCurl);
				fclose($out);

				if ($success && $dHttpCode === 200) {
					$ch->send(
						json_encode([
							"type" => "success",
							"message" => "Successfully installed $pname!",
							"filename" => $targetFilename,
							"path" => $savePath,
						]),
					);
				} else {
					@unlink($savePath);
					$ch->send(
						json_encode([
							"type" => "error",
							"message" => "Failed to download $targetFilename: " . ($dErr ?: "HTTP $dHttpCode"),
						]),
					);
				}

				$ch->close();
			},
			[
				$modDownloadChannel,
				$projectId,
				$projectType,
				$projectName,
				$cleanVer,
				$loader,
				$targetDir,
				$cacert,
			],
		);

		$this->pollEvents->addChannel($modDownloadChannel);
	}

	private function drawSearchResultCard($hit, $x, $y, $w, $h, $isHover, $alpha = 1.0)
	{
		// Glassmorphic Card Background (Themed)
		$bgColor = $isHover ? $this->colors["card_hover"] : $this->colors["card"];
		$bgColorWithAlpha = [$bgColor[0], $bgColor[1], $bgColor[2], ($bgColor[3] ?? 0.85) * $alpha];
		
		$this->drawRect($x, $y, $w, $h, $bgColorWithAlpha, 6);
		$this->drawRect($x, $y, $w, 1, [$this->colors["divider"][0], $this->colors["divider"][1], $this->colors["divider"][2], 0.3 * $alpha]);

		// Left accent bar on hover
		if ($isHover) {
			$pc = $this->colors["primary"];
			$this->drawRect($x, $y, 3, $h, [$pc[0], $pc[1], $pc[2], $alpha]);
		}

		// Data extraction
		$title = $hit["title"] ?? "Unknown";
		$author = $hit["author"] ?? "Unknown";
		$summary = $hit["description"] ?? "";
		$projId = $hit["project_id"] ?? "";
		$slug = $hit["slug"] ?? $projId;

		$isInstalled = isset($this->installedMods[$slug]) || isset($this->installedModpacks[$slug]);

		$iconSize = 64;
		$textOffsetX = 16;
		$iconX = $x + 16;
		$iconY = $y + 16;

		// Icon rendering
		$hasIcon = $projId && isset($this->modIconCache[$projId]) && $this->modIconCache[$projId] > 0;
		if ($hasIcon) {
			$this->modIconLastUse[$projId] = microtime(true);
			$iAlpha = $this->modIconAlpha[$projId] ?? 1.0;
			$this->drawTexture($this->modIconCache[$projId], $iconX, $iconY, $iconSize, $iconSize, [1,1,1,$alpha * $iAlpha]);
			$textOffsetX = 16 + $iconSize + 16;
		} else {
			// Placeholder
			$phColor = [$this->colors["text_dim"][0], $this->colors["text_dim"][1], $this->colors["text_dim"][2], 0.05 * $alpha];
			$this->drawRect($iconX, $iconY, $iconSize, $iconSize, $phColor);
			$textOffsetX = 16 + $iconSize + 16;
		}
		$this->opengl32->glDisable(0x0de1);

		// Install Button & External Link (Placed top right)
		$btnW = 100;
		$btnH = 32;
		$btnX = $x + $w - $btnW - 16;
		$btnY2 = $y + 16;
		
		$btnSize = 32;
		$btnGap = 8;
		$brX = $btnX - $btnSize - $btnGap;

		// Format Title
		$targetW = ($brX - $x) - $textOffsetX - 10; 
		$titleFont = 2000;
		if ($this->getTextWidth($title, $titleFont) > $targetW && strlen($title) > 20) {
			$title = substr($title, 0, 18) . "...";
		}

		// Main Texts
		$tc = $this->colors["text"];
		$td = $this->colors["text_dim"];
		$this->renderText($title, $x + $textOffsetX, $y + 30, [$tc[0], $tc[1], $tc[2], $alpha], $titleFont);
		$this->renderText("by $author", $x + $textOffsetX, $y + 50, [$td[0], $td[1], $td[2], $alpha], 3000);

		// Description truncation
		$maxChars = (int) ((($x + $w - 16) - ($x + $textOffsetX)) / 6.5);
		if (strlen($summary) > $maxChars) {
			$summary = substr($summary, 0, $maxChars - 3) . "...";
		}
		$this->renderText($summary, $x + $textOffsetX, $y + 88, [$td[0], $td[1], $td[2], $alpha], 1000);

		// Stats formatting
		$dl = $hit["downloads"] ?? 0;
		$dlStr = $dl > 1000000 ? round($dl / 1000000, 1) . "M" : ($dl > 1000 ? round($dl / 1000, 1) . "K" : (string)$dl);
		$follows = $hit["follows"] ?? 0;
		$flStr = $follows > 1000 ? round($follows / 1000, 1) . "K" : (string)$follows;

		// Stats Badges
		$statsY = $y + 68;
		$dlTw = $this->getTextWidth("DL $dlStr", 3000) + 12;
		$this->drawRect($x + $textOffsetX, $statsY - 11, $dlTw, 16, [0,0,0,0.2*$alpha]);
		$this->renderText("DL $dlStr", $x + $textOffsetX + 6, $statsY, [$td[0], $td[1], $td[2], $alpha], 3000);
		
		$favTw = $this->getTextWidth("FAV $flStr", 3000) + 12;
		$this->drawRect($x + $textOffsetX + $dlTw + 8, $statsY - 11, $favTw, 16, [0,0,0,0.2*$alpha]);
		$this->renderText("FAV $flStr", $x + $textOffsetX + $dlTw + 14, $statsY, [$td[0], $td[1], $td[2], $alpha], 3000);

		if (isset($this->modDownloadProgresses[$projId])) {
			// Render Progress Bar
			$this->drawRect($btnX, $btnY2, $btnW, $btnH, [0, 0, 0, 0.4 * $alpha], 4);
			$fillW = ($this->modDownloadProgresses[$projId] / 100.0) * $btnW;
			$pc = $this->colors["primary"];
			if ($fillW > 0) {
				$this->drawRect($btnX, $btnY2, (int)$fillW, $btnH, [$pc[0], $pc[1], $pc[2], 0.8 * $alpha], 4);
			}
			$pctText = round($this->modDownloadProgresses[$projId]) . "%";
			$this->renderText($pctText, $btnX + ($btnW - $this->getTextWidth($pctText, 1000))/2, $btnY2 + 21, [1, 1, 1, $alpha], 1000);
		} elseif ($isInstalled) {
			// Render Installed badge
			$statusColor = $this->colors["status_done"];
			$this->drawRect($btnX, $btnY2, $btnW, $btnH, [$statusColor[0], $statusColor[1], $statusColor[2], 0.2 * $alpha], 4);
			$this->renderText("INSTALLED", $btnX + ($btnW - $this->getTextWidth("INSTALLED", 3000))/2, $btnY2 + 20, [$statusColor[0], $statusColor[1], $statusColor[2], $alpha], 3000);
		} else {
			// Themed button colors for search results
			$bc = $isHover ? $this->colors["primary"] : $this->colors["info_bg"];
			$btnColor = [$bc[0], $bc[1], $bc[2], 0.9 * $alpha];
			$this->drawRect($btnX, $btnY2, $btnW, $btnH, $btnColor, 4);
			
			$btnText = $this->modpackSubTab === 1 ? "+ INSTALL" : "DOWNLOAD";
			$btc = $isHover ? [0,0,0,$alpha] : [$tc[0], $tc[1], $tc[2], $alpha];
			$this->renderText($btnText, $btnX + ($btnW - $this->getTextWidth($btnText, 1000))/2, $btnY2 + 21, $btc, 1000);
		}

		// Browser/External link button
		$brColor = [$this->colors["subtab"][0], $this->colors["subtab"][1], $this->colors["subtab"][2], 0.85 * $alpha];
		$this->drawRect($brX, $btnY2, $btnSize, $btnSize, $brColor, 4);

		// Draw external link icon (box with arrow)
		$ec = $this->colors["text_dim"];
		$ecA = [$ec[0], $ec[1], $ec[2], $alpha];
		$bx = $brX + 7;
		$by = $btnY2 + 8;
		$bs = 12;
		// Box outline
		$this->drawRect($bx, $by + 4, 2, $bs - 4, $ecA);
		$this->drawRect($bx, $by + $bs, $bs, 2, $ecA);
		$this->drawRect($bx + $bs, $by + 4, 2, $bs - 2, $ecA);
		// Arrow
		$this->drawLine($bx + 5, $by + $bs - 3, $bx + $bs + 2, $by - 2, $ecA);
		$this->drawRect($bx + $bs - 2, $by - 2, 6, 2, $ecA);
		$this->drawRect($bx + $bs + 2, $by - 2, 2, 6, $ecA);
	}

	private function triggerCheckForUpdate($silent = false)
	{
		if ($this->isCheckingUiUpdate) return;
		$this->isCheckingUiUpdate = true;
		if (!$silent) $this->updateMessage = "Checking Github for updates...";

		if (!$this->updateChannel) {
			$this->updateChannel = new \parallel\Channel(1024);
			$this->pollEvents->addChannel($this->updateChannel);
		}
		$ch = $this->updateChannel;
		$proc = new \parallel\Runtime();
		$cacert = self::CACERT;
		
		$f = $proc->run(function(\parallel\Channel $ch, $cacert) {
			try {
				$url = "https://api.github.com/repos/Minosuko/FoxyClient/releases/latest";
				$curl = curl_init($url);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_USERAGENT, "FoxyClient-Updater");
				curl_setopt($curl, CURLOPT_TIMEOUT, 15);
				curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
				if (file_exists($cacert)) {
					curl_setopt($curl, CURLOPT_CAINFO, $cacert);
				}
				
				$json = curl_exec($curl);
				$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
				$curlErr = curl_error($curl);
				curl_close($curl);
				
				if ($json === false) {
					$ch->send(['type' => 'ui_update_err', 'msg' => "Network error: $curlErr"]);
					return;
				}

				if ($code === 200 && $json) {
					$data = json_decode($json, true);
					if (isset($data['tag_name'])) {
						$ch->send(['type' => 'ui_update_res', 'version' => $data['tag_name']]);
					} else {
						$ch->send(['type' => 'ui_update_err', 'msg' => 'Invalid release data format.']);
					}
				} else {
					$ch->send(['type' => 'ui_update_err', 'msg' => "HTTP $code failed to fetch."]);
				}
			} catch (\Throwable $e) {
				$ch->send(['type' => 'ui_update_err', 'msg' => "Crash: " . $e->getMessage()]);
			}
		}, [$ch, $cacert]);
		
		$this->pendingFutures[] = $f;
	}

	private function performSelfUpdate()
	{
		$this->updateMessage = "Launching FoxyClient Updater... Please wait.";
		$this->needsRedraw = true;
		
		// Launch wrapper with --update
		$wrapper = "Client.exe";
		if (file_exists($wrapper)) {
			pclose(popen("start \"\" \"$wrapper\" --update", "r"));
			$this->running = false; // This will trigger exit
		} else {
			$this->updateMessage = "Error: Client.exe wrapper not found.";
		}
	}
}

class FoxyVersionJob
{
	public const VERSION = FoxyClient::VERSION;
	public static function run(
		\parallel\Channel $ch,
		string $version,
		string $gamesDir,
		string $cacert,
	) {
		$fetch = function ($url) use ($cacert) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			if (file_exists($cacert)) {
				curl_setopt($ch, CURLOPT_CAINFO, $cacert);
			}
			$data = curl_exec($ch);
			curl_close($ch);
			return $data;
		};

		$download = function ($url, $path, $sha1) use ($cacert) {
			if (file_exists($path) && $sha1 && sha1_file($path) === $sha1) {
				return true;
			}
			$dir = dirname($path);
			if (!is_dir($dir)) {
				@mkdir($dir, 0777, true);
			}
			$fp = fopen($path, "w+");
			$curl = curl_init($url);
			curl_setopt($curl, CURLOPT_FILE, $fp);
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
			if (file_exists($cacert)) {
				curl_setopt($curl, CURLOPT_CAINFO, $cacert);
			}
			curl_exec($curl);
			curl_close($curl);
			fclose($fp);
			if ($sha1 && sha1_file($path) !== $sha1) {
				@unlink($path);
				return false;
			}
			return true;
		};

		$versionJsonPath =
			$gamesDir .
			DIRECTORY_SEPARATOR .
			"versions" .
			DIRECTORY_SEPARATOR .
			$version .
			DIRECTORY_SEPARATOR .
			$version .
			".json";
		$versionData = null;

		$ch->send(
			json_encode([
				"type" => "status",
				"message" => "Resolving version manifest...",
			]),
		);
		$resolveVersionData = function ($vId) use (
			&$resolveVersionData,
			$gamesDir,
			$fetch,
			$ch,
		) {
			$vJsonPath =
				$gamesDir .
				DIRECTORY_SEPARATOR .
				"versions" .
				DIRECTORY_SEPARATOR .
				$vId .
				DIRECTORY_SEPARATOR .
				$vId .
				".json";
			$vData = null;
			$vUrl = "";

			// 1. Try Mojang
			$manifestUrl =
				"https://piston-meta.mojang.com/mc/game/version_manifest_v2.json";
			$manifestData = json_decode($fetch($manifestUrl), true);
			if ($manifestData && isset($manifestData["versions"])) {
				foreach ($manifestData["versions"] as $v) {
					if ($v["id"] === $vId) {
						$vUrl = $v["url"];
						break;
					}
				}
			}

			// 2. Try Secondary
			if (!$vUrl) {
				$modManifest = json_decode(
					$fetch("https://repo.llaun.ch/versions/versions.json"),
					true,
				);
				if ($modManifest && isset($modManifest["versions"])) {
					foreach ($modManifest["versions"] as $v) {
						if ($v["id"] === $vId) {
							$vUrl = $v["url"];
							break;
						}
					}
				}
			}

			if ($vUrl) {
				$vData = json_decode($fetch($vUrl), true);
				if ($vData) {
					if (!is_dir(dirname($vJsonPath))) {
						@mkdir(dirname($vJsonPath), 0777, true);
					}
					file_put_contents(
						$vJsonPath,
						json_encode($vData, JSON_PRETTY_PRINT),
					);
				}
			} elseif (file_exists($vJsonPath)) {
				$vData = json_decode(file_get_contents($vJsonPath), true);
			}

			if (!$vData) {
				return null;
			}

			if (isset($vData["inheritsFrom"])) {
				$parentData = $resolveVersionData($vData["inheritsFrom"]);
				if ($parentData) {
					// Merge libraries (CHILD FIRST for classpath priority)
					if (isset($parentData["libraries"])) {
						$vData["libraries"] = array_merge(
							$vData["libraries"] ?? [],
							$parentData["libraries"],
						);
					}
					// Merge fields
					foreach (
						[
							"mainClass",
							"assetIndex",
							"assets",
							"javaVersion",
							"downloads",
						]
						as $key
					) {
						if (!isset($vData[$key]) && isset($parentData[$key])) {
							$vData[$key] = $parentData[$key];
						}
					}
				}
			}
			return $vData;
		};

		$versionData = $resolveVersionData($version);

		if (!$versionData) {
			$ch->send(
				json_encode([
					"type" => "error",
					"message" => "Version $version metadata could not be resolved!",
				]),
			);
			return;
		}

		$ch->send(
			json_encode([
				"type" => "status",
				"message" => "Calculating total download size...",
			]),
		);
		$assetIndex = $versionData["assetIndex"];
		$assetIndexPath =
			$gamesDir .
			DIRECTORY_SEPARATOR .
			"assets" .
			DIRECTORY_SEPARATOR .
			"indexes" .
			DIRECTORY_SEPARATOR .
			$assetIndex["id"] .
			".json";
		if (!file_exists($assetIndexPath)) {
			$dir = dirname($assetIndexPath);
			if (!is_dir($dir)) {
				@mkdir($dir, 0777, true);
			}
			$fp = fopen($assetIndexPath, "w+");
			$curl = curl_init($assetIndex["url"]);
			curl_setopt($curl, CURLOPT_FILE, $fp);
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
			if (file_exists($cacert)) {
				curl_setopt($curl, CURLOPT_CAINFO, $cacert);
			}
			curl_exec($curl);
			curl_close($curl);
			fclose($fp);
		}
		$assetData = json_decode(file_get_contents($assetIndexPath), true);
		$mapToResources = $assetData["map_to_resources"] ?? false;

		$getLibraryResources = function ($lib) use ($gamesDir) {
			$results = [];

			// 1. Artifact
			if (isset($lib["downloads"]["artifact"])) {
				$a = $lib["downloads"]["artifact"];
				$results[] = [
					"path" =>
						$gamesDir .
						DIRECTORY_SEPARATOR .
						"libraries" .
						DIRECTORY_SEPARATOR .
						str_replace("/", DIRECTORY_SEPARATOR, $a["path"]),
					"url" => $a["url"],
					"sha1" => $a["sha1"] ?? "",
					"size" => $a["size"] ?? 0,
				];
			} elseif (isset($lib["name"]) && !isset($lib["natives"])) {
				// Fallback for older format
				$parts = explode(":", $lib["name"]);
				if (count($parts) >= 3) {
					$group = str_replace(".", DIRECTORY_SEPARATOR, $parts[0]);
					$name = $parts[1];
					$v = $parts[2];
					$classifier = $parts[3] ?? "";
					$relPath =
						$group .
						DIRECTORY_SEPARATOR .
						$name .
						DIRECTORY_SEPARATOR .
						$v .
						DIRECTORY_SEPARATOR .
						$name .
						"-" .
						$v .
						($classifier ? "-$classifier" : "") .
						".jar";
					$path =
						$gamesDir .
						DIRECTORY_SEPARATOR .
						"libraries" .
						DIRECTORY_SEPARATOR .
						$relPath;
					$url = "";
					if (isset($lib["url"])) {
						$baseUrl = $lib["url"];
						if ($baseUrl === "/libraries/") {
							$baseUrl = "https://repo.llaun.ch/libraries/";
						}
						$url =
							rtrim($baseUrl, "/") .
							"/" .
							str_replace(DIRECTORY_SEPARATOR, "/", $relPath);
					}
					if ($url) {
						$results[] = [
							"path" => $path,
							"url" => $url,
							"sha1" => "",
							"size" => 0,
						];
					}
				}
			}

			// 2. Natives (Windows only for now)
			if (isset($lib["natives"]["windows"])) {
				$classifier = $lib["natives"]["windows"];
				if (isset($lib["downloads"]["classifiers"][$classifier])) {
					$a = $lib["downloads"]["classifiers"][$classifier];
					$results[] = [
						"path" =>
							$gamesDir .
							DIRECTORY_SEPARATOR .
							"libraries" .
							DIRECTORY_SEPARATOR .
							str_replace("/", DIRECTORY_SEPARATOR, $a["path"]),
						"url" => $a["url"],
						"sha1" => $a["sha1"] ?? "",
						"size" => $a["size"] ?? 0,
					];
				}
			}

			return $results;
		};

		$totalBytes = 0;
		if (isset($versionData["downloads"]["client"]["size"])) {
			$totalBytes += $versionData["downloads"]["client"]["size"];
		}

		$totalFiles = 1; // client jar
		$totalFilesCount = 0;
		foreach ($versionData["libraries"] as $lib) {
			$artifacts = $getLibraryResources($lib);
			foreach ($artifacts as $a) {
				if (isset($a["size"])) {
					$totalBytes += $a["size"];
				}
				$totalFilesCount++;
			}
		}
		$totalFiles += $totalFilesCount;

		if ($assetData && isset($assetData["objects"])) {
			foreach ($assetData["objects"] as $obj) {
				if (isset($obj["size"])) {
					$totalBytes += $obj["size"];
					if ($mapToResources) {
						$totalBytes += $obj["size"];
					}
				}
			}
			$totalFiles += count($assetData["objects"]);
			if ($mapToResources) {
				$totalFiles += count($assetData["objects"]);
			}
		}

		$downloadedFiles = 0;
		$downloadedBytes = 0;
		$lastTime = microtime(true);
		$lastBytes = 0;
		$downloadQueue = [];

		$queueDownload = function ($url, $path, $sha1, $expectedSize = 0) use (
			&$downloadQueue,
		) {
			$downloadQueue[] = [
				"url" => $url,
				"path" => $path,
				"sha1" => $sha1,
				"size" => $expectedSize,
			];
		};

		$ch->send(
			json_encode([
				"type" => "status",
				"message" => "Queuing client JAR...",
			]),
		);
		$clientJar =
			$gamesDir .
			DIRECTORY_SEPARATOR .
			"versions" .
			DIRECTORY_SEPARATOR .
			$version .
			DIRECTORY_SEPARATOR .
			$version .
			".jar";
		if (isset($versionData["downloads"]["client"]["url"])) {
			$queueDownload(
				$versionData["downloads"]["client"]["url"],
				$clientJar,
				$versionData["downloads"]["client"]["sha1"] ?? "",
				$versionData["downloads"]["client"]["size"] ?? 0,
			);
		}

		$libraries = $versionData["libraries"];
		foreach ($libraries as $i => $lib) {
			$artifacts = $getLibraryResources($lib);
			foreach ($artifacts as $a) {
				$queueDownload($a["url"], $a["path"], $a["sha1"], $a["size"]);
			}
		}

		$ch->send(
			json_encode([
				"type" => "status",
				"message" => "Queuing version assets...",
			]),
		);
		$objects = $assetData["objects"] ?? [];
		foreach ($objects as $name => $obj) {
			$hash = $obj["hash"];
			$prefix = substr($hash, 0, 2);
			$assetPath =
				$gamesDir .
				DIRECTORY_SEPARATOR .
				"assets" .
				DIRECTORY_SEPARATOR .
				"objects" .
				DIRECTORY_SEPARATOR .
				$prefix .
				DIRECTORY_SEPARATOR .
				$hash;
			$url =
				"https://resources.download.minecraft.net/" .
				$prefix .
				"/" .
				$hash;
			$queueDownload($url, $assetPath, $hash, $obj["size"] ?? 0);

			if ($mapToResources) {
				$resPath =
					$gamesDir .
					DIRECTORY_SEPARATOR .
					"resources" .
					DIRECTORY_SEPARATOR .
					str_replace("/", DIRECTORY_SEPARATOR, $name);
				$queueDownload($url, $resPath, $hash, $obj["size"] ?? 0);
			}
		}

		$ch->send(
			json_encode([
				"type" => "status",
				"message" =>
					"Downloading files (0/" . count($downloadQueue) . ")...",
			]),
		);

		$mh = curl_multi_init();
		$activeTransfers = [];
		$maxConcurrent = 64;
		$queueIndex = 0;

		$reportProgress = function () use (
			$ch,
			&$totalBytes,
			&$downloadedBytes,
			&$lastTime,
			&$lastBytes,
			&$totalFiles,
			&$downloadedFiles,
			&$activeTransfers,
		) {
			$now = microtime(true);
			if ($now - $lastTime >= 0.1) {
				// Calculate current total downloaded bytes
				$currentTotalBytes = $downloadedBytes;
				foreach ($activeTransfers as $transfer) {
					$currentTotalBytes += $transfer["current_downloaded"] ?? 0;
				}

				$diffTime = $now - $lastTime;
				$speedStr = "";
				if ($diffTime > 0) {
					$speed = ($currentTotalBytes - $lastBytes) / $diffTime;
					if ($speed > 1024 * 1024) {
						$speedStr = round($speed / (1024 * 1024), 1) . " MB/s";
					} elseif ($speed > 1024) {
						$speedStr = round($speed / 1024, 1) . " KB/s";
					} else {
						$speedStr = round($speed) . " B/s";
					}
				}

				$totalStr = "";
				$dlStr = "";
				if ($totalBytes > 1024 * 1024) {
					$totalStr = round($totalBytes / (1024 * 1024), 2) . " MB";
					$dlStr =
						round($currentTotalBytes / (1024 * 1024), 2) . " MB";
				} else {
					$totalStr = round($totalBytes / 1024, 2) . " KB";
					$dlStr = round($currentTotalBytes / 1024, 2) . " KB";
				}

				$pct =
					$totalBytes > 0
						? round(($currentTotalBytes / $totalBytes) * 100)
						: 0;
				if ($pct > 100) {
					$pct = 100;
				}

				$msg = "$downloadedFiles / $totalFiles files | $dlStr / $totalStr ($pct%) | $speedStr";
				$ch->send(
					json_encode([
						"type" => "progress",
						"pct" => $pct,
						"msg" => $msg,
					]),
				);

				$lastTime = $now;
				$lastBytes = $currentTotalBytes;
			}
		};

		$events = new \parallel\Events();
		$events->setBlocking(false);
		$events->addChannel($ch);

		while (
			$queueIndex < count($downloadQueue) ||
			count($activeTransfers) > 0
		) {
			// Check for shutdown signal
			try {
				if ($ev = $events->poll()) {
					if ($ev->value === "shutdown") {
						break;
					}
					// Re-add if it was some other message (though shouldn't happen here)
					$events->addChannel($ch);
				}
			} catch (\parallel\Events\Error\Timeout $e) {}
			// Fill up the active transfers
			while (
				count($activeTransfers) < $maxConcurrent &&
				$queueIndex < count($downloadQueue)
			) {
				$item = $downloadQueue[$queueIndex++];

				if (
					file_exists($item["path"]) &&
					$item["sha1"] &&
					sha1_file($item["path"]) === $item["sha1"]
				) {
					$downloadedBytes += $item["size"];
					$downloadedFiles++;
					$reportProgress();
					continue;
				}

				$dir = dirname($item["path"]);
				if (!is_dir($dir)) {
					@mkdir($dir, 0777, true);
				}

				$fp = fopen($item["path"], "w+");
				$curl = curl_init($item["url"]);
				curl_setopt($curl, CURLOPT_FILE, $fp);
				curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
				if (file_exists($cacert)) {
					curl_setopt($curl, CURLOPT_CAINFO, $cacert);
				}
				curl_setopt($curl, CURLOPT_NOPROGRESS, false);

				$id = (int) $curl;
				curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, function (
					$resource,
					$download_size,
					$current_downloaded,
					$upload_size,
					$uploaded,
				) use (&$activeTransfers, $id) {
					if (isset($activeTransfers[$id])) {
						$activeTransfers[$id][
							"current_downloaded"
						] = $current_downloaded;
					}
				});

				curl_multi_add_handle($mh, $curl);
				$activeTransfers[$id] = [
					"curl" => $curl,
					"fp" => $fp,
					"item" => $item,
					"current_downloaded" => 0,
				];
			}

			if (count($activeTransfers) > 0) {
				do {
					$mrc = curl_multi_exec($mh, $active);
				} while ($mrc == CURLM_CALL_MULTI_PERFORM);

				while ($info = curl_multi_info_read($mh)) {
					$handle = $info["handle"];
					$id = (int) $handle;

					if (isset($activeTransfers[$id])) {
						$transfer = $activeTransfers[$id];
						$item = $transfer["item"];

						curl_multi_remove_handle($mh, $handle);
						curl_close($handle);
						fclose($transfer["fp"]);

						// Error checking / retries can go here if needed
						if (
							$item["sha1"] &&
							sha1_file($item["path"]) !== $item["sha1"]
						) {
							@unlink($item["path"]); // Corrupted
							// Wait to re-queue or just fail? Could requeue here
						} else {
							$downloadedBytes += $item["size"];
							$downloadedFiles++;
						}

						unset($activeTransfers[$id]);
					}
				}

				if ($active) {
					curl_multi_select($mh, 0.001);
				}
				$reportProgress();
			}
		}

		curl_multi_close($mh);
		$ch->send(
			json_encode([
				"type" => "progress",
				"msg" => "All version files ready!",
				"done" => true,
			]),
		);
		$ch->close();
	}
}

class FoxyCompatCheckJob
{
	public const VERSION = FoxyClient::VERSION;
	public static function run(
		\parallel\Channel $ch,
		array $modIds,
		string $mcVersion,
		string $loader,
	) {
		$baseUrl = "https://api.modrinth.com/v2";

		$fetchJson = function ($endpoint, $params = []) use ($baseUrl) {
			$url = $baseUrl . $endpoint;
			if (!empty($params)) {
				$url .= "?" . http_build_query($params);
			}
			$ch_curl = curl_init($url);
			curl_setopt($ch_curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch_curl, CURLOPT_USERAGENT, "FoxyClient/" . self::VERSION);
			curl_setopt($ch_curl, CURLOPT_TIMEOUT, 10);
			$res = curl_exec($ch_curl);
			curl_close($ch_curl);
			return $res ? json_decode($res, true) : null;
		};

		$events = new \parallel\Events();
		$events->setBlocking(false);
		$events->addChannel($ch);

		foreach ($modIds as $id) {
			// Check for shutdown signal
			try {
				if ($ev = $events->poll()) {
					if ($ev->value === "shutdown") break;
					$events->addChannel($ch);
				}
			} catch (\parallel\Events\Error\Timeout $e) {}

			try {
				$versions = $fetchJson("/project/$id/version", [
					"loaders" => json_encode([$loader]),
					"game_versions" => json_encode([$mcVersion]),
				]);

				$result = !empty($versions) ? "compatible" : "incompatible";
				$ch->send(
					json_encode([
						"type" => "compat",
						"mod" => $id,
						"result" => $result,
					]),
				);
			} catch (\Throwable $e) {
				$ch->send(
					json_encode([
						"type" => "compat",
						"mod" => $id,
						"result" => "incompatible",
					]),
				);
			}
		}
		$ch->close();
	}
}

class FoxyModrinthJob
{
	public const VERSION = FoxyClient::VERSION;
	public static function run(
		\parallel\Channel $ch,
		array $modIds,
		string $modsDir,
		string $mcVersion,
		string $loader,
	) {
		$baseUrl = "https://api.modrinth.com/v2";

		$fetchJson = function ($endpoint, $params = []) use ($baseUrl) {
			$url = $baseUrl . $endpoint;
			if (!empty($params)) {
				$url .= "?" . http_build_query($params);
			}
			$ch_curl = curl_init($url);
			curl_setopt($ch_curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch_curl, CURLOPT_USERAGENT, "FoxyClient/" . self::VERSION);
			curl_setopt($ch_curl, CURLOPT_TIMEOUT, 20);
			$res = curl_exec($ch_curl);
			curl_close($ch_curl);
			return $res ? json_decode($res, true) : null;
		};

		$events = new \parallel\Events();
		$events->setBlocking(false);
		$events->addChannel($ch);

		foreach ($modIds as $id) {
			// Check for shutdown signal
			try {
				if ($ev = $events->poll()) {
					if ($ev->value === "shutdown") break;
					$events->addChannel($ch);
				}
			} catch (\parallel\Events\Error\Timeout $e) {}

			$project = $fetchJson("/project/$id");
			if (!$project) {
				$ch->send(
					json_encode([
						"type" => "status",
						"mod" => $id,
						"state" => "failed",
						"message" => "Project not found",
					]),
				);
				continue;
			}
			$versions = $fetchJson("/project/$id/version", [
				"loaders" => json_encode([$loader]),
				"game_versions" => json_encode([$mcVersion]),
			]);
			if (empty($versions)) {
				$ch->send(
					json_encode([
						"type" => "status",
						"mod" => $id,
						"state" => "skip",
						"message" => "No compatible version",
					]),
				);
				continue;
			}

			$latestVersion = $versions[0];
			$file = $latestVersion["files"][0];
			$targetPath = $modsDir . DIRECTORY_SEPARATOR . $file["filename"];

			if (file_exists($targetPath)) {
				$ch->send(
					json_encode([
						"type" => "status",
						"mod" => $id,
						"state" => "ok",
						"message" => "Up to date",
					]),
				);
				continue;
			}

			// Delete old versions of this mod before downloading the new one
			$slug = $project["slug"] ?? $id;
			$slugLower = strtolower($slug);
			$newFileLower = strtolower($file["filename"]);
			foreach (scandir($modsDir) as $existingFile) {
				if ($existingFile === "." || $existingFile === "..") continue;
				$existingLower = strtolower($existingFile);
				if (!str_ends_with($existingLower, ".jar")) continue;
				if ($existingLower === $newFileLower) continue; // Don't delete the target
				// Match if existing file starts with the mod slug followed by a separator
				if (
					str_starts_with($existingLower, $slugLower . "-") ||
					str_starts_with($existingLower, $slugLower . "_") ||
					$existingLower === $slugLower . ".jar"
				) {
					$oldPath = $modsDir . DIRECTORY_SEPARATOR . $existingFile;
					@unlink($oldPath);
					$ch->send(
						json_encode([
							"type" => "status",
							"mod" => $id,
							"state" => "cleanup",
							"message" => "Removed old version: $existingFile",
						]),
					);
				}
			}

			$ch->send(
				json_encode([
					"type" => "status",
					"mod" => $id,
					"state" => "downloading",
					"version" => $latestVersion["version_number"],
					"pct" => 0,
				]),
			);

			$fp = fopen($targetPath, "w+");
			$curl = curl_init($file["url"]);
			curl_setopt($curl, CURLOPT_FILE, $fp);
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt(
				$curl,
				CURLOPT_USERAGENT,
				"FoxyClient/" . self::VERSION,
			);
			curl_setopt($curl, CURLOPT_NOPROGRESS, false);

			$lastTime = microtime(true);
			curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, function (
				$resource,
				$download_size,
				$downloaded,
				$upload_size,
				$uploaded,
			) use ($ch, $id, &$lastTime) {
				if ($download_size > 0) {
					$now = microtime(true);
					if ($now - $lastTime >= 0.1) {
						$pct = (int) (($downloaded / $download_size) * 100);
						$ch->send(
							json_encode([
								"type" => "status",
								"mod" => $id,
								"state" => "downloading",
								"pct" => $pct,
							]),
						);
						$lastTime = $now;
					}
				}
			});

			curl_exec($curl);
			curl_close($curl);
			fclose($fp);

			$ch->send(
				json_encode([
					"type" => "status",
					"mod" => $id,
					"state" => "done",
				]),
			);
		}
		$ch->close();
	}
}

if (getenv("FOXY_BACKGROUND") !== "1") {
	$client = new FoxyClient();
	$client->run();
}

/**
 * Lightweight Discord RPC implementation via Named Pipes
 */
class DiscordRPC
{
	private $pipe = null;
	private $clientId = "";
	private $startTime = null;

	public function init($clientId)
	{
		$this->clientId = $clientId;
		$found = false;
		for ($i = 0; $i < 10; $i++) {
			$pipePath = "\\\\.\\pipe\\discord-ipc-$i";
			$this->pipe = @fopen($pipePath, "rb+");
			if ($this->pipe) {
				$found = true;
				break;
			}
		}

		if (!$found) {
			return false;
		}

		stream_set_blocking($this->pipe, false);

		// Handshake
		$this->send(0, ["v" => 1, "client_id" => $clientId]);

		// Discord needs a moment to process handshake before first activity
		usleep(200000);

		return true;
	}

	public function updatePresence(
		$details,
		$state,
		$largeImage = "foxy_logo",
		$largeText = "FoxyClient",
		$smallImage = null,
		$smallText = null,
		$buttons = [],
	) {
		if (!$this->pipe) {
			return;
		}

		if ($this->startTime === null) {
			$this->startTime = time();
		}
		$nonce = uniqid();

		$activity = [
			"details" => $details,
			"state" => $state,
			"timestamps" => [
				"start" => $this->startTime,
			],
			"assets" => [
				"large_image" => $largeImage,
				"large_text" => $largeText,
			],
		];

		if ($smallImage) {
			$activity["assets"]["small_image"] = $smallImage;
			if ($smallText) {
				$activity["assets"]["small_text"] = $smallText;
			}
		}

		if (!empty($buttons)) {
			$activity["buttons"] = $buttons;
		}

		$payload = [
			"cmd" => "SET_ACTIVITY",
			"args" => [
				"pid" => getmypid(),
				"activity" => $activity,
			],
			"nonce" => $nonce,
		];

		$this->send(1, $payload);
	}

	public function send($opcode, $payload)
	{
		if (!$this->pipe) {
			return;
		}

		$json = json_encode($payload);
		$frame = pack("VV", $opcode, strlen($json)) . $json;
		@fwrite($this->pipe, $frame);
	}

	public function close()
	{
		if ($this->pipe) {
			fclose($this->pipe);
			$this->pipe = null;
		}
	}
}