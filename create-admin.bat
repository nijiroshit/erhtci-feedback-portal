@echo off
"C:\xampp\php\php.exe" "%~dp0create-admin.php"
if errorlevel 1 (
  echo Admin setup failed.
)
pause
