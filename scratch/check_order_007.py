import subprocess

cmd = """php -r "require 'config/app.php'; require 'core/Database.php'; \$pdo = Database::getInstance(); \$stmt = \$pdo->query('SELECT * FROM orders ORDER BY id DESC LIMIT 5'); print_r(\$stmt->fetchAll(PDO::FETCH_ASSOC));" """
res = subprocess.run(["powershell", "-Command", cmd], capture_output=True, text=True)
print(res.stdout)
print(res.stderr)
