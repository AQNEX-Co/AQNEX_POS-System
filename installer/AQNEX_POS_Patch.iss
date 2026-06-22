; ==============================================================
; AQNEX Business Solutions - Inno Setup Patch/Update Script
; Application: AQNEX POS/ERP Update Patch
; Architecture: x64
; ==============================================================

#define MyAppName "AQNEX POS Update"
#define MyAppVersion "1.0.1"
#define MyAppPublisher "AQNEX Business Solutions"
#define MyAppURL "https://ameenqahtan.com/AQNEX/"
#define MyAppExeName "{reg:HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\msedge.exe,|{commonpf32}\Microsoft\Edge\Application\msedge.exe}"

[Setup]
; نفس معرف التطبيق لضمان التحديث على نفس المجلد
AppId={{A9B8C7D6-E5F4-4321-B1A2-C3D4E5F6A7B8}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}
AppUpdatesURL={#MyAppURL}
; المجلد الافتراضي للتنصيب الخاص بالبرنامج الأصلي
DefaultDirName=C:\AQNEX_POS
DefaultGroupName={#MyAppPublisher}
DisableProgramGroupPage=yes
; اسم ملف التحديث الناتج
OutputBaseFilename=AQNEX_POS_Update_Patch
; استخدام الأصول المرئية المشتركة
SetupIconFile=assets\icon.ico
WizardSmallImageFile=assets\logo_small.bmp
WizardImageFile=assets\logo_large.bmp
; الإعدادات العامة والضغط
Compression=lzma2/ultra64
SolidCompression=yes
ArchitecturesInstallIn64BitMode=x64compatible
DisableWelcomePage=no
DisableDirPage=no
DisableFinishedPage=no
PrivilegesRequired=admin

[Languages]
Name: "arabic"; MessagesFile: "compiler:Languages\Arabic.isl"
Name: "english"; MessagesFile: "compiler:Default.isl"

[Files]
; 1. نسخ الملفات الأربعة المحدثة فقط وتجاوز القديمة
Source: "..\core\bootstrap.php"; DestDir: "{app}\app\core"; Flags: ignoreversion
Source: "..\auth\setup_wizard.php"; DestDir: "{app}\app\auth"; Flags: ignoreversion
Source: "..\auth\activate.php"; DestDir: "{app}\app\auth"; Flags: ignoreversion
Source: "..\settings\index.php"; DestDir: "{app}\app\settings"; Flags: ignoreversion

; 2. نسخ سكربت تحديث قاعدة البيانات للمجلد المؤقت
Source: "apply_db_patch.php"; DestDir: "{tmp}"; Flags: ignoreversion deleteafterinstall

[Run]
; 1. تشغيل سكربت تحديث قاعدة البيانات تلقائياً وتمرير مسار البرنامج له
Filename: "{app}\runtime\php\php.exe"; Parameters: "-f ""{tmp}\apply_db_patch.php"" ""{app}"""; StatusMsg: "جاري فحص وتحديث هيكل قاعدة البيانات..."; Flags: runhidden

; 2. تشغيل التطبيق تلقائياً بعد اكتمال الترقية
Filename: "{#MyAppExeName}"; Parameters: "--app=http://localhost:8181/index.php"; Description: "تشغيل النظام الآن بعد التحديث"; Flags: postinstall nowait shellexec
