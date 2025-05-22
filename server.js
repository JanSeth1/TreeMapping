const express = require('express');
const cors = require('cors');
const mysql = require('mysql2/promise');

const app = express();
app.use(cors());
app.use(express.json());

// Update these with your MySQL credentials
const pool = mysql.createPool({
  host: 'localhost',
  user: 'root',
  password: '', // your MySQL password
  database: 'treemap',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
});

// Create table if not exists
(async () => {
  const conn = await pool.getConnection();
  await conn.query(`
    CREATE TABLE IF NOT EXISTS trees (
      id INT AUTO_INCREMENT PRIMARY KEY,
      type VARCHAR(50),
      lat DOUBLE,
      lng DOUBLE,
      description TEXT,
      photo_path VARCHAR(255),
      status VARCHAR(20) DEFAULT 'pending',
      user_id VARCHAR(50),
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
  `);
  conn.release();
})();

app.get('/trees', async (req, res) => {
  const [rows] = await pool.query('SELECT * FROM trees');
  res.json(rows);
});

app.post('/trees', async (req, res) => {
  const { type, lat, lng } = req.body;
  const [result] = await pool.query(
    'INSERT INTO trees (type, lat, lng) VALUES (?, ?, ?)',
    [type, lat, lng]
  );
  res.json({ id: result.insertId, type, lat, lng });
});

app.get('/mock', async (req, res) => {
  await pool.query('DELETE FROM trees');
  await pool.query(`
    INSERT INTO trees (type, lat, lng) VALUES
    ('Oak', 9.753, 118.759),
    ('Oak', 9.751, 118.761),
    ('Maple', 9.754, 118.757),
    ('Maple', 9.752, 118.7585),
    ('Pine', 9.7505, 118.7605),
    ('Pine', 9.7535, 118.758)
  `);
  res.send('Mock data inserted');
});

app.listen(3001, () => console.log('Server running on port 3001'));