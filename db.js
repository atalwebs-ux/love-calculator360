const mysql = require('mysql2/promise');
require('dotenv').config();

const DB_HOST = process.env.DB_HOST || 'localhost';
const DB_PORT = Number(process.env.DB_PORT || 3306);
const DB_USER = process.env.DB_USER || 'u785941294_lovecal';
const DB_PASSWORD = process.env.DB_PASSWORD || '';
const DB_NAME = process.env.DB_NAME || 'u785941294_lovecal';

if (!DB_PASSWORD) {
  console.warn('⚠️ DB_PASSWORD env variable is not set. MySQL queries will fail until configured.');
}

const pool = mysql.createPool({
  host: DB_HOST,
  port: DB_PORT,
  user: DB_USER,
  password: DB_PASSWORD,
  database: DB_NAME,
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
  connectTimeout: 8000,
});

async function query(sql, params = []) {
  const [rows] = await pool.execute(sql, params);
  return rows;
}

async function initDb() {
  const statements = [
    `CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      email VARCHAR(255) NULL UNIQUE,
      phone_number VARCHAR(32) NULL UNIQUE,
      firebase_uid VARCHAR(128) NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,

    `CREATE TABLE IF NOT EXISTS calculations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      feature_type VARCHAR(32) NOT NULL DEFAULT 'love',
      your_name VARCHAR(120) NOT NULL,
      partner_name VARCHAR(120) NOT NULL,
      score INT NOT NULL,
      tier_title VARCHAR(140) NOT NULL,
      details_json TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_calculations_user_id (user_id),
      INDEX idx_calculations_feature_type (feature_type),
      CONSTRAINT fk_calculations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,

    `CREATE TABLE IF NOT EXISTS daily_predictions (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      date_key VARCHAR(16) NOT NULL,
      category VARCHAR(16) NOT NULL,
      mood VARCHAR(80) NOT NULL,
      lucky_color VARCHAR(60) NOT NULL,
      lucky_number INT NOT NULL,
      message TEXT NOT NULL,
      advice TEXT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_user_date_cat (user_id, date_key, category),
      CONSTRAINT fk_predictions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,

    `CREATE TABLE IF NOT EXISTS favorite_tips (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      tip_id VARCHAR(32) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_user_tip (user_id, tip_id),
      CONSTRAINT fk_tips_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,

    `CREATE TABLE IF NOT EXISTS game_sessions (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      game_id VARCHAR(40) NOT NULL,
      score INT NOT NULL,
      total_questions INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_games_user_id (user_id),
      CONSTRAINT fk_games_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  ];

  try {
    const rows = await query('SELECT NOW() AS now');
    console.log('✅ Hostinger MySQL connected successfully at:', rows[0].now);
    for (const stmt of statements) {
      await pool.query(stmt);
    }
    console.log('✅ MySQL tables initialized (users, calculations, daily_predictions, favorite_tips, game_sessions)');
  } catch (err) {
    console.error('❌ Hostinger MySQL connection/init error:', err.message);
  }
}

initDb();

module.exports = {
  query,
  pool,
};
