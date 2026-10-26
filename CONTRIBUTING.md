# Contributing to RideSync

Thank you for your interest in contributing to **RideSync**! RideSync is an open-source campus mobility, geodesic carpooling, and driver dispatch platform.

---

## 🛠️ Development Setup

1. Fork and clone the repository:
   ```bash
   git clone https://github.com/<your-username>/ridesync.git
   cd ridesync
   ```
2. Start your local Apache/PHP 8.2+ and MySQL environment (e.g. XAMPP).
3. Import the database schema from `database/ridesync_db.sql`.
4. Copy `.env.example` to `.env` and configure your local credentials.

---

## 🧪 Testing Standards

Before submitting a pull request, ensure all tests pass:

```bash
# Syntax and static architecture verification
npm run test:syntax

# Full quality gate suite (Unit, Regression, and Smoke checks)
npm test
```

---

## 📝 Pull Request Guidelines

1. Create a feature branch from `main`:
   ```bash
   git checkout -b feat/your-feature-name
   ```
2. Write clean, readable code with descriptive comments where appropriate.
3. Keep pull requests focused on a single issue or feature.
4. Ensure all quality gates pass cleanly without regressions.
