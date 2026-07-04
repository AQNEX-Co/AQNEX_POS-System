; ==============================================================
; AQNEX Business Solutions - Inno Setup Script (Offline Edition)
; Application: AQNEX POS/ERP
; Architecture: x64
; Notes: Fully offline installer - runtime.zip is bundled locally,
;        no internet connection required during installation.
; ==============================================================

#define MyAppName "AQNEX POS"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "AQNEX Business Solutions"
#define MyAppURL "https://ameenqahtan.com/AQNEX/"
#define MyAppExeName "{reg:HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\msedge.exe,|{commonpf32}\Microsoft\Edge\Application\msedge.exe}"

[Setup]
; Unique App ID (kept identical to avoid duplicate Add/Remove Programs entries on upgrade)
AppId={{A9B8C7D6-E5F4-4321-B1A2-C3D4E5F6A7B8}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}
AppUpdatesURL={#MyAppURL}
; Default installation directory
DefaultDirName=C:\AQNEX_POS
DefaultGroupName={#MyAppPublisher}
DisableProgramGroupPage=yes
; Setup output file name (renamed slightly to mark the offline build)
OutputBaseFilename=AQNEX_POS_Offline_Setup
; Visual Assets
SetupIconFile=assets\icon.ico
WizardSmallImageFile=assets\logo_small.bmp
WizardImageFile=assets\logo_large.bmp
; Compression settings (LZMA2 ultra - keeps final EXE as small as possible
; even though runtime.zip is bundled inside)
Compression=lzma2/ultra64
SolidCompression=yes
; Force 64-bit mode
ArchitecturesInstallIn64BitMode=x64compatible
; UI Settings
DisableWelcomePage=no
DisableDirPage=no
DisableFinishedPage=no
PrivilegesRequired=admin

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"
Name: "arabic"; MessagesFile: "compiler:Languages\Arabic.isl"

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked

[Files]
; 1. Application source files (excludes sensitive developer-only folders/files)
Source: "..\*"; DestDir: "{app}\app"; Excludes: "installer\*, runtime\*, *.zip, *.iss, clean.bat, files_list.txt, license_manager\*, licensing_system\*, show_pass.php, update_patch.php"; Flags: recursesubdirs createallsubdirs ignoreversion

; 2. Visual Assets for the Shortcut
Source: "assets\logo.png"; DestDir: "{app}\installer\assets"; Flags: ignoreversion
Source: "assets\icon.ico"; DestDir: "{app}\installer\assets"; Flags: ignoreversion

; 2.5. Installer Helper Scripts
Source: "configure_paths.php"; DestDir: "{app}\installer"; Flags: ignoreversion
Source: "run_init_db.php"; DestDir: "{app}\installer"; Flags: ignoreversion

; 3. Visual C++ Redistributable (local, no download)
Source: "redist\VC_redist.x64.exe"; DestDir: "{tmp}"; Flags: deleteafterinstall

; 4. Runtime package (Apache, PHP, MariaDB) - BUNDLED LOCALLY, no internet needed
; Place runtime.zip next to this .iss file inside a "runtime" folder before compiling.
Source: "runtime\runtime.zip"; DestDir: "{tmp}"; Flags: deleteafterinstall

[Dirs]
Name: "{app}\runtime"
Name: "{app}\backups"

[Icons]
; Shortcut to launch the system as an Edge Web App (Port 8181)
Name: "{commondesktop}\{#MyAppName}"; Filename: "{#MyAppExeName}"; Parameters: "--app=http://localhost:8181/index.php"; IconFilename: "{app}\installer\assets\icon.ico"; Tasks: desktopicon
Name: "{commonprograms}\{#MyAppName}"; Filename: "{#MyAppExeName}"; Parameters: "--app=http://localhost:8181/index.php"; IconFilename: "{app}\installer\assets\icon.ico"

[Run]
; 1. Install Visual C++ Redistributable Silently (Only if needed)
Filename: "{tmp}\VC_redist.x64.exe"; Parameters: "/install /quiet /norestart"; StatusMsg: "Installing System Components (VC++ Redistributable)..."; Check: NeedsFramework

; 2. Extract the Runtime Package (Apache, PHP, MariaDB) - reads from the bundled local file
Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -Command ""Expand-Archive -Path '{tmp}\runtime.zip' -DestinationPath '{app}' -Force"""; StatusMsg: "Configuring server environment..."; Flags: runhidden

; 3. Run Path Configuration Script (Updates httpd.conf, php.ini, and my.ini)
Filename: "{app}\runtime\php\php.exe"; Parameters: "-f ""{app}\installer\configure_paths.php"" ""{app}"""; StatusMsg: "Applying local path configurations..."; Flags: runhidden

; 4. Register Apache and MariaDB as Windows Services
Filename: "{app}\runtime\apache\bin\httpd.exe"; Parameters: "-k install -n ""AQNEX_Apache"""; StatusMsg: "Registering Web Server Service..."; Flags: runhidden
Filename: "{app}\runtime\mariadb\bin\mariadbd.exe"; Parameters: "--install ""AQNEX_MariaDB"""; StatusMsg: "Registering Database Service..."; Flags: runhidden

; 5. Start Services
Filename: "sc.exe"; Parameters: "start AQNEX_MariaDB"; Flags: runhidden
Filename: "sc.exe"; Parameters: "start AQNEX_Apache"; Flags: runhidden

; 6. Initialize Database and Import Schema (Crucial for offline DB setup)
Filename: "{app}\runtime\php\php.exe"; Parameters: "-f ""{app}\installer\run_init_db.php"" ""{app}"""; StatusMsg: "Initializing database and schemas..."; Flags: runhidden

; 7. Launch the App after Installation
Filename: "{#MyAppExeName}"; Parameters: "--app=http://localhost:8181/index.php"; Description: "{cm:LaunchProgram,{#StringChange(MyAppName, '&', '&&')}}"; Flags: postinstall nowait shellexec

[UninstallRun]
; Stop and Remove Services on Uninstall
Filename: "sc.exe"; Parameters: "stop AQNEX_Apache"; Flags: runhidden; RunOnceId: "StopApache"
Filename: "sc.exe"; Parameters: "stop AQNEX_MariaDB"; Flags: runhidden; RunOnceId: "StopMariaDB"
Filename: "{app}\runtime\apache\bin\httpd.exe"; Parameters: "-k uninstall -n ""AQNEX_Apache"""; Flags: runhidden; RunOnceId: "UninstallApache"
Filename: "{app}\runtime\mariadb\bin\mariadbd.exe"; Parameters: "--remove ""AQNEX_MariaDB"""; Flags: runhidden; RunOnceId: "UninstallMariaDB"

[Code]
// ==============================================================
// No download logic needed anymore - everything is bundled locally.
// Only basic environment checks remain.
// ==============================================================

// Check for VC++ Redistributable (x64)
function NeedsFramework(): Boolean;
begin
  Result := not RegKeyExists(HKLM, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64');
end;

function InitializeSetup(): Boolean;
begin
  Result := True;
  // Ensure we are on 64-bit OS
  if not Is64BitInstallMode then begin
    MsgBox('This application requires a 64-bit version of Windows.', mbCriticalError, MB_OK);
    Result := False;
  end;
end;
