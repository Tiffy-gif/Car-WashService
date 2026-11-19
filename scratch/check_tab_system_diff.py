import subprocess

cmd = ['git', 'diff', '49620b7', 'HEAD', '--', 'views/owner/settings.php']
diff = subprocess.check_output(cmd, encoding='utf-8')

for line in diff.splitlines():
    if 'tab_system' in line or 'system_preferences' in line or 'GROUP 10' in line or 'GROUP 11' in line or 'GROUP 7' in line:
        print(line)
