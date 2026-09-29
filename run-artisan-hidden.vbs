' Runs a single artisan command with no visible console window, for Windows
' Task Scheduler entries. Usage: wscript run-artisan-hidden.vbs "scrape:autovit"
Set WshShell = CreateObject("WScript.Shell")
WshShell.CurrentDirectory = "C:\a.coding\olx"

artisanCommand = WScript.Arguments(0)

WshShell.Run """C:\Users\cioara\.config\herd\bin\php85\php.exe"" artisan " & artisanCommand, 0, True
