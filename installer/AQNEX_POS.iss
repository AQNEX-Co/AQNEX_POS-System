; ==============================================================
; AQNEX Business Solutions - Inno Setup Script
; Application: AQNEX POS/ERP System
; Architecture: x64
; ==============================================================

#define MyAppName "AQNEX POS"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "AQNEX Business Solutions"
#define MyAppURL "https://ameenqahtan.com/AQNEX/"
#define MyAppExeName "{reg:HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\msedge.exe,|{commonpf32}\Microsoft\Edge\Application\msedge.exe}"

[Setup]
; Unique App ID (Generated for AQNEX)
AppId={{A9B8C7D6-E5F4-4321-B1A2-C3D4E5F6A7B8}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}
AppUpdatesURL={#MyAppURL}
; Default installation directory (C:\AQNEX_POS is recommended to avoid permission issues)
DefaultDirName=C:\AQNEX_POS
DefaultGroupName={#MyAppPublisher}
DisableProgramGroupPage=yes
; Setup output file name
OutputBaseFilename=AQNEX_POS_Setup
; Visual Assets
SetupIconFile=assets\icon.ico
WizardSmallImageFile=assets\logo_small.bmp
WizardImageFile=assets\logo_large.bmp
; Compression settings
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
Name: "arabic"; MessagesFile: "compiler:Languages\Arabic.isl"
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "إنشاء اختصار تشغيل المساعد لنظام AQNEX على سطح المكتب"; GroupDescription: "خيارات التثبيت"; Flags: checkedonce
Name: "install_db"; Description: "تثبيت وتجهيز خادم قاعدة البيانات المحلي الشامل (MariaDB المنفذ 3307)"; GroupDescription: "قواعد البيانات"; Flags: checkedonce

[Files]
; 1. All files and folders from project root
Source: "..\*"; DestDir: "{app}\app"; Excludes: "installer\*, runtime\*, *.zip, *.iss, clean.bat, files_list.txt, license_manager\*, licensing_system\*, show_pass.php, update_patch.php"; Flags: recursesubdirs createallsubdirs ignoreversion

; 2. Visual Assets for Shortcut & Icons
Source: "assets\logo.png"; DestDir: "{app}\installer\assets"; Flags: ignoreversion
Source: "assets\icon.ico"; DestDir: "{app}\installer\assets"; Flags: ignoreversion

; 2.5. Installer Helper Scripts
Source: "configure_paths.php"; DestDir: "{app}\installer"; Flags: ignoreversion
Source: "run_init_db.php"; DestDir: "{app}\installer"; Flags: ignoreversion

; 3. Visual C++ Redistributable (Silent Installer)
Source: "redist\VC_redist.x64.exe"; DestDir: "{tmp}"; Flags: deleteafterinstall

; 4. Runtime package (Apache, PHP, MariaDB) - BUNDLED LOCALLY
Source: "runtime\runtime.zip"; DestDir: "{tmp}"; Flags: deleteafterinstall

[Dirs]
Name: "{app}\runtime"
Name: "{app}\backups"

[Icons]
; Shortcut to launch system as standalone Web App (Port 8181)
Name: "{commondesktop}\{#MyAppName}"; Filename: "{#MyAppExeName}"; Parameters: "--app=http://localhost:8181/index.php"; IconFilename: "{app}\installer\assets\icon.ico"; Tasks: desktopicon
Name: "{commonprograms}\{#MyAppName}"; Filename: "{#MyAppExeName}"; Parameters: "--app=http://localhost:8181/index.php"; IconFilename: "{app}\installer\assets\icon.ico"

[Run]
; 1. Install Visual C++ Redistributable (x64)
Filename: "{tmp}\VC_redist.x64.exe"; Parameters: "/install /quiet /norestart"; StatusMsg: "جاري تثبيت مكونات النظام الضرورية (VC++ Redistributable)..."; Check: NeedsFramework

; 2. Extract Server Runtime Package
Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -Command ""Expand-Archive -Path '{tmp}\runtime.zip' -DestinationPath '{app}' -Force"""; StatusMsg: "جاري استخراج وتجهيز بيئة الخادم المحلي (Apache/PHP/MariaDB)..."; Flags: runhidden

; 3. Apply Local Path Configuration (httpd.conf, php.ini, my.ini)
Filename: "{app}\runtime\php\php.exe"; Parameters: "-f ""{app}\installer\configure_paths.php"" ""{app}"" ""local"""; StatusMsg: "جاري إعداد ضوابط الخادم والمسارات المحلية..."; Flags: runhidden; Tasks: install_db
Filename: "{app}\runtime\php\php.exe"; Parameters: "-f ""{app}\installer\configure_paths.php"" ""{app}"" ""external"""; StatusMsg: "جاري إعداد ضوابط الخادم والمسارات المحلية..."; Flags: runhidden; Tasks: not install_db

; 4. Register Services
Filename: "{app}\runtime\apache\bin\httpd.exe"; Parameters: "-k install -n ""AQNEX_Apache"""; StatusMsg: "جاري تسجيل خدمة خادم الويب (AQNEX_Apache)..."; Flags: runhidden
Filename: "{app}\runtime\mariadb\bin\mariadbd.exe"; Parameters: "--install ""AQNEX_MariaDB"""; StatusMsg: "جاري تسجيل خدمة خادم قاعدة البيانات (AQNEX_MariaDB)..."; Flags: runhidden; Tasks: install_db

; 5. Start Services
Filename: "sc.exe"; Parameters: "start AQNEX_MariaDB"; Flags: runhidden; Tasks: install_db
Filename: "sc.exe"; Parameters: "start AQNEX_Apache"; Flags: runhidden

; 5.5. Pause 10 Seconds for MariaDB initialization
Filename: "powershell.exe"; Parameters: "-Command ""Start-Sleep -Seconds 10"""; StatusMsg: "جاري انتظار اكتمال تشغيل خادم قاعدة البيانات..."; Flags: runhidden; Tasks: install_db

; 6. Initialize Database & Seed Clean Client Records
Filename: "{app}\runtime\php\php.exe"; Parameters: "-f ""{app}\installer\run_init_db.php"" ""{app}"""; StatusMsg: "جاري إنشاء قاعدة البيانات النظيفة للعميل وتطبيق الهجرات والتثبيت التأسيسي..."; Flags: runhidden; Tasks: install_db

; 7. Launch System App After Finish
Filename: "{#MyAppExeName}"; Parameters: "--app=http://localhost:8181/index.php"; Description: "تشغيل تطبيق AQNEX POS الان"; Flags: postinstall nowait shellexec

[UninstallRun]
; Stop and Uninstall Services
Filename: "sc.exe"; Parameters: "stop AQNEX_Apache"; Flags: runhidden; RunOnceId: "StopApache"
Filename: "sc.exe"; Parameters: "stop AQNEX_MariaDB"; Flags: runhidden; RunOnceId: "StopMariaDB"
Filename: "{app}\runtime\apache\bin\httpd.exe"; Parameters: "-k uninstall -n ""AQNEX_Apache"""; Flags: runhidden; RunOnceId: "UninstallApache"
Filename: "{app}\runtime\mariadb\bin\mariadbd.exe"; Parameters: "--remove ""AQNEX_MariaDB"""; Flags: runhidden; RunOnceId: "UninstallMariaDB"

[Code]
function NeedsFramework(): Boolean;
begin
  Result := not RegKeyExists(HKLM, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64');
end;

function InitializeSetup(): Boolean;
begin
  Result := True;
  if not Is64BitInstallMode then begin
    MsgBox('يتطلب هذا البرنامج نظام تشغيل ويندوز 64-بت.', mbCriticalError, MB_OK);
    Result := False;
  end;
end;