const mysql = require('mysql2/promise');

(async () => {
    try {
        const conn = await mysql.createConnection({
            host: 'localhost',
            user: 'root',
            password: '',
            database: 'carwash_db'
        });
        const [rows] = await conn.query('SELECT id, username, full_name, role, pin, is_active FROM staff');
        console.log('Staff list:', rows);
        await conn.end();
    } catch (e) {
        console.error(e);
    }
})();
