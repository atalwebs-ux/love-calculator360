const express = require('express');
const cors = require('cors');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
require('dotenv').config();

const db = require('./db');

const app = express();
const PORT = process.env.PORT || 5000;
const JWT_SECRET = process.env.JWT_SECRET || 'love_calculator_fallback_change_me';
const GOOGLE_WEB_CLIENT_ID =
  process.env.GOOGLE_WEB_CLIENT_ID ||
  '690786932656-oiachl1n0b11dgnl2hk85coebk160jsq.apps.googleusercontent.com';

app.use(cors());
app.use(express.json());

app.use((req, res, next) => {
  console.log(`[${new Date().toISOString()}] ${req.method} ${req.url}`);
  next();
});

function authenticateToken(req, res, next) {
  const authHeader = req.headers['authorization'];
  const token = authHeader && authHeader.split(' ')[1];

  if (!token) {
    return res.status(401).json({ error: 'Access token required' });
  }

  jwt.verify(token, JWT_SECRET, (err, user) => {
    if (err) {
      return res.status(403).json({ error: 'Invalid or expired token' });
    }
    req.user = user;
    next();
  });
}

// Health check
app.get(['/api/health', '/'], async (req, res) => {
  try {
    const rows = await db.query('SELECT NOW() AS now');
    res.json({
      status: 'ok',
      service: 'Love Calculator 360 MySQL API',
      database: 'MySQL Connected',
      timestamp: rows[0].now,
    });
  } catch (err) {
    res.status(500).json({ status: 'error', database: err.message });
  }
});

// 1. REGISTER
app.post('/api/auth/register', async (req, res) => {
  try {
    const { name, email, password } = req.body;

    if (!name || !email || !password) {
      return res.status(400).json({ error: 'All fields (name, email, password) are required' });
    }

    const trimmedEmail = email.trim().toLowerCase();
    const trimmedName = name.trim();

    if (password.length < 6) {
      return res.status(400).json({ error: 'Password must be at least 6 characters' });
    }

    const existing = await db.query('SELECT id FROM users WHERE email = ?', [trimmedEmail]);
    if (existing.length > 0) {
      return res.status(409).json({ error: 'An account with this email already exists' });
    }

    const salt = await bcrypt.genSalt(10);
    const passwordHash = await bcrypt.hash(password, salt);

    const insertResult = await db.query(
      'INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)',
      [trimmedName, trimmedEmail, passwordHash]
    );

    const users = await db.query(
      'SELECT id, name, email, created_at FROM users WHERE id = ?',
      [insertResult.insertId]
    );
    const user = users[0];

    const token = jwt.sign(
      { id: user.id, email: user.email, name: user.name },
      JWT_SECRET,
      { expiresIn: '30d' }
    );

    res.status(201).json({
      message: 'Account created successfully!',
      token,
      user: {
        id: user.id,
        name: user.name,
        email: user.email,
        createdAt: user.created_at,
      },
    });
  } catch (err) {
    console.error('Registration error:', err);
    res.status(500).json({ error: 'Internal server error during registration' });
  }
});

// 2. LOGIN
app.post('/api/auth/login', async (req, res) => {
  try {
    const { email, password } = req.body;

    if (!email || !password) {
      return res.status(400).json({ error: 'Email and password are required' });
    }

    const trimmedEmail = email.trim().toLowerCase();
    const rows = await db.query('SELECT * FROM users WHERE email = ?', [trimmedEmail]);

    if (rows.length === 0) {
      return res.status(401).json({ error: 'Invalid email or password' });
    }

    const user = rows[0];
    const isMatch = await bcrypt.compare(password, user.password_hash);
    if (!isMatch) {
      return res.status(401).json({ error: 'Invalid email or password' });
    }

    const token = jwt.sign(
      { id: user.id, email: user.email, name: user.name },
      JWT_SECRET,
      { expiresIn: '30d' }
    );

    res.json({
      message: 'Login successful!',
      token,
      user: {
        id: user.id,
        name: user.name,
        email: user.email,
        createdAt: user.created_at,
      },
    });
  } catch (err) {
    console.error('Login error:', err);
    res.status(500).json({ error: 'Internal server error during login' });
  }
});

// 2b. GOOGLE SIGN IN
app.post('/api/auth/google', async (req, res) => {
  try {
    let { email, name, idToken } = req.body;

    if (idToken) {
      let verified = null;
      try {
        const resp = await fetch(
          `https://oauth2.googleapis.com/tokeninfo?id_token=${encodeURIComponent(idToken)}`
        );
        if (resp.ok) {
          verified = await resp.json();
        } else if (resp.status >= 400 && resp.status < 500) {
          return res.status(401).json({ error: 'Invalid Google ID token' });
        }
      } catch {
        // Fallback to JWT payload decode if outbound lookup fails
      }

      if (!verified) {
        const parts = String(idToken).split('.');
        if (parts.length === 3) {
          try {
            verified = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8'));
          } catch {
            // ignore malformed token payload
          }
        }
      }

      if (verified && verified.email) {
        if (verified.aud && verified.aud !== GOOGLE_WEB_CLIENT_ID) {
          return res.status(401).json({ error: 'Google token audience mismatch' });
        }
        email = verified.email;
        if (!name && verified.name) {
          name = verified.name;
        }
      }
    }

    if (!email) {
      return res.status(400).json({ error: 'Email is required for Google authentication' });
    }

    const trimmedEmail = String(email).trim().toLowerCase();
    const displayName = (name && String(name).trim().slice(0, 100)) || trimmedEmail.split('@')[0];

    const rows = await db.query(
      'SELECT id, name, email, created_at FROM users WHERE email = ?',
      [trimmedEmail]
    );

    let user;
    if (rows.length === 0) {
      const insertResult = await db.query(
        'INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)',
        [displayName, trimmedEmail, 'GOOGLE_OAUTH_USER']
      );
      const created = await db.query(
        'SELECT id, name, email, created_at FROM users WHERE id = ?',
        [insertResult.insertId]
      );
      user = created[0];
    } else {
      user = rows[0];
    }

    const token = jwt.sign(
      { id: user.id, email: user.email, name: user.name },
      JWT_SECRET,
      { expiresIn: '30d' }
    );

    res.json({
      message: 'Google Sign-In successful!',
      token,
      user: {
        id: user.id,
        name: user.name,
        email: user.email,
        createdAt: user.created_at,
      },
    });
  } catch (err) {
    console.error('Google Sign-In error:', err);
    res.status(500).json({ error: 'Failed to authenticate with Google' });
  }
});

// 2c. FIREBASE AUTH SYNC
app.post('/api/auth/firebase', async (req, res) => {
  try {
    const { firebaseUid, phoneNumber, email, name } = req.body;
    if (!firebaseUid) {
      return res.status(400).json({ error: 'firebaseUid is required' });
    }

    const trimmedUid = String(firebaseUid).trim();
    const trimmedPhone = phoneNumber ? String(phoneNumber).trim() : null;
    const trimmedEmail = email ? String(email).trim().toLowerCase() : null;
    const displayName =
      (name && String(name).trim()) ||
      trimmedPhone ||
      (trimmedEmail ? trimmedEmail.split('@')[0] : 'Love User');

    let rows = await db.query(
      'SELECT id, name, email, phone_number, firebase_uid, created_at FROM users WHERE firebase_uid = ?',
      [trimmedUid]
    );

    if (rows.length === 0 && trimmedPhone) {
      rows = await db.query(
        'SELECT id, name, email, phone_number, firebase_uid, created_at FROM users WHERE phone_number = ?',
        [trimmedPhone]
      );
    }

    if (rows.length === 0 && trimmedEmail) {
      rows = await db.query(
        'SELECT id, name, email, phone_number, firebase_uid, created_at FROM users WHERE email = ?',
        [trimmedEmail]
      );
    }

    let user;
    if (rows.length === 0) {
      const insertResult = await db.query(
        'INSERT INTO users (name, email, phone_number, firebase_uid, password_hash) VALUES (?, ?, ?, ?, ?)',
        [displayName, trimmedEmail, trimmedPhone, trimmedUid, 'FIREBASE_AUTH_USER']
      );
      const created = await db.query(
        'SELECT id, name, email, phone_number, firebase_uid, created_at FROM users WHERE id = ?',
        [insertResult.insertId]
      );
      user = created[0];
    } else {
      const existing = rows[0];
      await db.query(
        `UPDATE users
         SET firebase_uid = COALESCE(firebase_uid, ?),
             phone_number = COALESCE(?, phone_number),
             email = COALESCE(?, email),
             name = COALESCE(NULLIF(?, ''), name)
         WHERE id = ?`,
        [trimmedUid, trimmedPhone, trimmedEmail, displayName, existing.id]
      );
      const updated = await db.query(
        'SELECT id, name, email, phone_number, firebase_uid, created_at FROM users WHERE id = ?',
        [existing.id]
      );
      user = updated[0];
    }

    const token = jwt.sign(
      {
        id: user.id,
        firebaseUid: user.firebase_uid,
        phoneNumber: user.phone_number,
        email: user.email,
        name: user.name,
      },
      JWT_SECRET,
      { expiresIn: '30d' }
    );

    res.json({
      message: 'Firebase user synced with MySQL!',
      token,
      user: {
        id: user.id,
        name: user.name,
        email: user.email || '',
        phoneNumber: user.phone_number || undefined,
        firebaseUid: user.firebase_uid || trimmedUid,
        createdAt: user.created_at,
      },
    });
  } catch (err) {
    console.error('Firebase auth sync error:', err);
    res.status(500).json({ error: 'Failed to sync Firebase user with MySQL' });
  }
});

// 3. GET CURRENT USER PROFILE
app.get('/api/auth/me', authenticateToken, async (req, res) => {
  try {
    const rows = await db.query(
      'SELECT id, name, email, phone_number, firebase_uid, created_at FROM users WHERE id = ?',
      [req.user.id]
    );

    if (rows.length === 0) {
      return res.status(404).json({ error: 'User not found' });
    }

    const u = rows[0];
    res.json({
      user: {
        id: u.id,
        name: u.name,
        email: u.email || '',
        phoneNumber: u.phone_number || undefined,
        firebaseUid: u.firebase_uid || undefined,
        createdAt: u.created_at,
      },
    });
  } catch (err) {
    console.error('Profile fetch error:', err);
    res.status(500).json({ error: 'Failed to fetch user profile' });
  }
});

// 4. SAVE CALCULATION
app.post('/api/calculations', authenticateToken, async (req, res) => {
  try {
    const {
      feature_type = 'love',
      your_name,
      partner_name,
      score,
      tier_title,
      details_json,
    } = req.body;

    if (!your_name || !partner_name || score === undefined) {
      return res.status(400).json({ error: 'Missing calculation details' });
    }

    const serializedDetails = details_json
      ? typeof details_json === 'string'
        ? details_json
        : JSON.stringify(details_json)
      : null;

    const insertResult = await db.query(
      `INSERT INTO calculations (user_id, feature_type, your_name, partner_name, score, tier_title, details_json)
       VALUES (?, ?, ?, ?, ?, ?, ?)`,
      [
        req.user.id,
        String(feature_type).slice(0, 32),
        String(your_name).trim().slice(0, 120),
        String(partner_name).trim().slice(0, 120),
        Number(score),
        String(tier_title || 'Match').slice(0, 140),
        serializedDetails,
      ]
    );

    const created = await db.query('SELECT * FROM calculations WHERE id = ?', [
      insertResult.insertId,
    ]);

    res.status(201).json({
      message: 'Saved to MySQL history',
      calculation: created[0],
    });
  } catch (err) {
    console.error('Save calculation error:', err);
    res.status(500).json({ error: 'Failed to save calculation' });
  }
});

// 5. GET USER'S CALCULATION HISTORY
app.get('/api/calculations', authenticateToken, async (req, res) => {
  try {
    const rows = await db.query(
      `SELECT id, feature_type, your_name, partner_name, score, tier_title, details_json, created_at
       FROM calculations
       WHERE user_id = ?
       ORDER BY created_at DESC
       LIMIT 60`,
      [req.user.id]
    );

    res.json({
      calculations: rows,
      total: rows.length,
    });
  } catch (err) {
    console.error('Fetch calculations error:', err);
    res.status(500).json({ error: 'Failed to fetch calculation history' });
  }
});

// 6. DELETE CALCULATION
app.delete('/api/calculations/:id', authenticateToken, async (req, res) => {
  try {
    await db.query('DELETE FROM calculations WHERE id = ? AND user_id = ?', [
      req.params.id,
      req.user.id,
    ]);
    res.json({ message: 'Calculation deleted successfully' });
  } catch (err) {
    console.error('Delete calculation error:', err);
    res.status(500).json({ error: 'Failed to delete calculation' });
  }
});

// 7. SAVE DAILY PREDICTION
app.post('/api/predictions', authenticateToken, async (req, res) => {
  try {
    const { date_key, category, mood, lucky_color, lucky_number, message, advice } =
      req.body;
    if (!date_key || !category || !message) {
      return res.status(400).json({ error: 'Missing daily prediction details' });
    }

    await db.query(
      `INSERT INTO daily_predictions (user_id, date_key, category, mood, lucky_color, lucky_number, message, advice)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         mood = VALUES(mood),
         lucky_color = VALUES(lucky_color),
         lucky_number = VALUES(lucky_number),
         message = VALUES(message),
         advice = VALUES(advice)`,
      [
        req.user.id,
        date_key,
        category,
        mood || '',
        lucky_color || '',
        Number(lucky_number || 7),
        message,
        advice || '',
      ]
    );

    res.status(201).json({ message: 'Daily prediction saved to MySQL' });
  } catch (err) {
    console.error('Save prediction error:', err);
    res.status(500).json({ error: 'Failed to save prediction' });
  }
});

// 8. GET & TOGGLE FAVORITE TIPS
app.get('/api/tips/favorites', authenticateToken, async (req, res) => {
  try {
    const rows = await db.query(
      'SELECT tip_id FROM favorite_tips WHERE user_id = ? ORDER BY created_at DESC',
      [req.user.id]
    );
    res.json({ favorites: rows.map((r) => r.tip_id) });
  } catch (err) {
    res.status(500).json({ error: 'Failed to load favorite tips' });
  }
});

app.post('/api/tips/favorites/toggle', authenticateToken, async (req, res) => {
  try {
    const { tip_id } = req.body;
    if (!tip_id) return res.status(400).json({ error: 'tip_id is required' });

    const existing = await db.query(
      'SELECT id FROM favorite_tips WHERE user_id = ? AND tip_id = ?',
      [req.user.id, tip_id]
    );

    if (existing.length > 0) {
      await db.query('DELETE FROM favorite_tips WHERE id = ?', [existing[0].id]);
    } else {
      await db.query('INSERT INTO favorite_tips (user_id, tip_id) VALUES (?, ?)', [
        req.user.id,
        tip_id,
      ]);
    }

    const rows = await db.query(
      'SELECT tip_id FROM favorite_tips WHERE user_id = ? ORDER BY created_at DESC',
      [req.user.id]
    );
    res.json({ favorites: rows.map((r) => r.tip_id) });
  } catch (err) {
    res.status(500).json({ error: 'Failed to toggle favorite tip' });
  }
});

// 9. SAVE COUPLE GAME SESSION
app.post('/api/games/sessions', authenticateToken, async (req, res) => {
  try {
    const { game_id, score, total_questions } = req.body;
    if (!game_id) return res.status(400).json({ error: 'game_id is required' });

    const insertResult = await db.query(
      'INSERT INTO game_sessions (user_id, game_id, score, total_questions) VALUES (?, ?, ?, ?)',
      [req.user.id, game_id, Number(score || 0), Number(total_questions || 10)]
    );

    res.status(201).json({
      message: 'Game session saved to MySQL',
      id: insertResult.insertId,
    });
  } catch (err) {
    res.status(500).json({ error: 'Failed to save game session' });
  }
});

app.listen(PORT, '0.0.0.0', () => {
  console.log(`🚀 Love Calculator 360 MySQL Server running on port ${PORT}`);
});
