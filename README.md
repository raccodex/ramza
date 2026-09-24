<p align="center">
  <h1 align="center">Ramza</h1>
  <p align="center"><strong>The Modern Open-Source Social Networking Platform</strong></p>
</p>

<p align="center">
  <a href="https://github.com/raccodex/ramza/blob/main/LICENSE"><img src="https://img.shields.io/badge/License-MIT-blue.svg" alt="License"></a>
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/Database-MySQL%20%7C%20MariaDB-orange.svg" alt="MySQL/MariaDB">
  <img src="https://img.shields.io/badge/Status-Active-brightgreen.svg" alt="Status">
</p>

---

Whether you're building a private community 🏢 a public social network 🌎 a startup 🚀 an organization platform 🤝 or an experimental social product 🧪 — Ramza is designed to give you the foundation to start building.

---

# ✨ What is Ramza?

**Ramza** is a modern, full-featured, open-source social networking platform built with PHP and JavaScript.

It brings together the core building blocks of a modern social platform including:

- 👤 **User Profiles:** Rich personal profiles, customizable avatars, covers, bio, and social links
- 📝 **Social Feed:** Rich multimedia posts, statuses, polls, links, audio, and video sharing
- ❤️ **Reactions & Engagement:** Modern emoji reactions, nested comments, reposts, and bookmarking
- 💬 **Private Messaging & Real-Time Chat:** Peer-to-peer and group messaging with audio/video calls
- 🔔 **Real-Time Notifications:** Instant activity alerts, badge counters, and push notifications
- 👥 **Pages & Communities:** Public and private groups, interest clubs, and brand pages
- 🔎 **Command Palette & Search:** Unified search modal (`Ctrl+K`) for rapid navigation and discovery
- 🛠️ **Next-Gen Administration:** Reference v4 admin console with dark mode, live stats, and deep controls
- 📱 **Mobile & API Ready:** REST APIs and PWA support built for web, iOS, and Android
- 🧩 **Zero Commercial Lock-in:** 100% open source, MIT-licensed, with no license gates or purchase codes

The goal is to create a **high-performance foundation developers and communities can freely build on.**

---

# 🚀 Core Features

### 👤 Social Profiles
Create rich user profiles with personal information, profile images, connections, activity, and social content.

### 📰 Social Feed
Publish posts, share content, interact with other users, and build an active community around your platform.

### ❤️ Engagement
Support modern social interactions through reactions, comments, shares, likes, and other engagement mechanisms.

### 💬 Messaging & Calls
Give users private communications, real-time messaging, and WebRTC voice & video calling.

### 👥 Communities & Pages
Create spaces where people with shared interests can interact, publish content, and build communities.

### 📸 Rich Media Sharing
Support user-generated content including photos, albums, audio recordings, video uploads, and embeds.

### 🔔 Notifications
Keep users connected with notifications for important interactions, mentions, likes, and comments.

### 🔎 Discovery & Unified Search
Command Palette (`Ctrl+K`) and smart search let users find people, pages, groups, and settings quickly.

### 🛠️ Administration Console
Manage users, content, site settings, design, monetization, and system health from an elegant admin panel.

### 📱 Mobile Ecosystem
Ramza includes clean mobile-responsive themes and RESTful endpoints designed for companion mobile applications.

---

# ⚡ Quick Start & Fresh Installation

Setting up Ramza on your server (Apache, Nginx, cPanel, Plesk, XAMPP, or VPS) takes only a couple of minutes through the built-in browser installer.

### 1. Server Requirements
- **PHP:** 8.2 or newer
- **Required Extensions:** `mysqli`, `mbstring`, `curl`, `gd`, `openssl`, `sodium`, `fileinfo`, `zip`, `json`, `session`
- **Database:** MySQL 5.7+ or MariaDB 10.3+
- **Web Server:** Apache (with `mod_rewrite`) or Nginx

### 2. Clone or Copy Files
Clone this repository directly into your web root directory (e.g. `public_html` or `/var/www/html`):

```bash
git clone https://github.com/raccodex/ramza.git .
```

*Or download the repository ZIP, extract it, and copy all files to your web server.*

### 3. File Permissions
Ensure your web server (e.g. `www-data` or your cPanel user) has write permissions to:
- `upload/`
- `cache/`
- The project root directory (needed by the installer to write `config.php`)

```bash
chmod -R 775 upload cache
```

### 4. Create an Empty Database
Create a new MySQL or MariaDB database and user in your hosting control panel or phpMyAdmin, granting full privileges.

### 5. Launch the Web Installer
Open your web browser and navigate to your site URL:

```text
http://your-domain.com/
```
*(or `http://localhost/ramza` in a local development environment)*

Ramza automatically detects a fresh installation and redirects you directly to the **Web Installer** at `/install/`.

### 6. Follow the Installer Steps
1. **Welcome:** Review the terms and click **Continue**.
2. **System Requirements:** Verify that all required PHP extensions and directory permissions pass.
3. **Database Setup:** Enter your MySQL Host (`localhost`), Database Name, User, and Password.
4. **Site & Admin Setup:** Enter your Site Name, Site URL, and set your Administrator username, email, and password.
5. **Run Installation:** Click **Install**. The installer imports `ramza.sql`, applies migrations, configures your admin account, safely writes `config.php`, and creates `install.lock`.

🎉 **Setup complete!** Click **Finish** to log in to your fresh Ramza network.

---

# 🧠 Built for Developers

Ramza is designed to be an open, developer-friendly platform:

```text
🔧 Customize → 🧩 Extend → 🚀 Build → 🌍 Deploy → 🤝 Contribute
```

- Clean procedural + modern PHP components
- Standard SQL schemas with automatic migrations
- Customizable themes (`themes/ramza`, `themes/ramza-light`)
- Flexible plugin & addon capabilities

---

# 🛣️ Roadmap

### ✅ Foundation
- [x] Open-source MIT repository
- [x] Streamlined fresh web installer (no license keys required)
- [x] Complete social feed, profiles, reactions, and messaging
- [x] Modern reference v4 admin console with unified search modal
- [x] Dark and light themes

### 🚧 In Progress
- [ ] Expanded developer API documentation
- [ ] Mobile app SDK & starter kits
- [ ] Webhook integration engine
- [ ] Additional localized language packs

---

# 🤝 Contributing

Contributions are welcome!

1. Fork the Project
2. Create your Feature Branch (`git checkout -b feature/AmazingFeature`)
3. Commit your Changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the Branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

---

# 📜 License

Distributed under the **MIT License**. See [`LICENSE`](LICENSE) for more details.

---

<p align="center">
  <strong>🚀 Build the social web you want.</strong><br>
  Made with ❤️ for developers who believe the internet should be open and buildable.
</p>
