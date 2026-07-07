<?php
/**
 * index.php
 * -----------------------------------------------------------------------
 * PromptCraft AI - Application Shell
 * Renders the Login/Register screens for guests, and the full dashboard
 * (sidebar + navbar + all pages as toggle-able sections) for logged in
 * users. All dynamic behaviour (page switching, AJAX calls to api.php,
 * charts, etc.) lives in script.js.
 * -----------------------------------------------------------------------
 */
session_start();
require_once 'db.php';

$isLoggedIn = !empty($_SESSION['user_id']);
$fullname   = $_SESSION['fullname'] ?? '';
$initials   = $fullname ? strtoupper(substr($fullname, 0, 1)) : 'U';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PromptCraft AI</title>
<link rel="stylesheet" href="style.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="<?= $isLoggedIn ? 'app-mode' : 'auth-mode' ?>">

<!-- Ambient background glow -->
<div class="bg-glow bg-glow-1"></div>
<div class="bg-glow bg-glow-2"></div>
<div class="bg-glow bg-glow-3"></div>

<?php if (!$isLoggedIn): ?>
<!-- ======================================================================
     AUTH SCREENS (Login / Register)
====================================================================== -->
<div class="auth-wrapper">
    <div class="auth-card glass">
        <div class="auth-brand">
            <div class="brand-logo">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none"><path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" fill="url(#g1)"/><defs><linearGradient id="g1" x1="2" y1="2" x2="22" y2="22"><stop stop-color="#A855F7"/><stop offset="1" stop-color="#7C3AED"/></linearGradient></defs></svg>
            </div>
            <h1>PromptCraft <span>AI</span></h1>
            <p>Craft, refine, and test AI prompts — beautifully.</p>
        </div>

        <div class="auth-tabs">
            <button class="auth-tab active" data-form="login-form">Login</button>
            <button class="auth-tab" data-form="register-form">Register</button>
        </div>

        <!-- LOGIN FORM -->
        <form id="login-form" class="auth-form active">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="you@example.com" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <p class="form-error" id="login-error"></p>
            <button type="submit" class="btn btn-primary btn-block ripple">Login to Dashboard</button>
        </form>

        <!-- REGISTER FORM -->
        <form id="register-form" class="auth-form">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="fullname" placeholder="John Doe" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="you@example.com" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="At least 6 characters" required>
            </div>
            <p class="form-error" id="register-error"></p>
            <p class="form-success" id="register-success"></p>
            <button type="submit" class="btn btn-primary btn-block ripple">Create Account</button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ======================================================================
     DASHBOARD APP
====================================================================== -->
<div class="app-shell">

    <!-- SIDEBAR ------------------------------------------------------- -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" fill="url(#g2)"/><defs><linearGradient id="g2" x1="2" y1="2" x2="22" y2="22"><stop stop-color="#A855F7"/><stop offset="1" stop-color="#7C3AED"/></linearGradient></defs></svg>
            <span>PromptCraft <em>AI</em></span>
        </div>

        <nav class="sidebar-nav">
            <a href="#" class="nav-item active" data-page="dashboard"><i class="ic-grid"></i><span>Dashboard</span></a>
            <a href="#" class="nav-item" data-page="generate"><i class="ic-wand"></i><span>Generate Prompt</span></a>
            <a href="#" class="nav-item" data-page="improve"><i class="ic-spark"></i><span>Improve Prompt</span></a>
            <a href="#" class="nav-item" data-page="test"><i class="ic-play"></i><span>Test Prompt</span></a>
            <a href="#" class="nav-item" data-page="library"><i class="ic-book"></i><span>Prompt Library</span></a>
            <a href="#" class="nav-item" data-page="favorites"><i class="ic-heart"></i><span>Favorites</span></a>
            <a href="#" class="nav-item" data-page="history"><i class="ic-clock"></i><span>History</span></a>
            <a href="#" class="nav-item" data-page="analytics"><i class="ic-chart"></i><span>Analytics</span></a>
            <a href="#" class="nav-item" data-page="profile"><i class="ic-user"></i><span>Profile</span></a>
            <a href="#" class="nav-item" data-page="settings"><i class="ic-gear"></i><span>Settings</span></a>
        </nav>

        <button class="nav-item logout-btn" id="logout-btn"><i class="ic-logout"></i><span>Logout</span></button>
    </aside>

    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <!-- MAIN AREA ------------------------------------------------------ -->
    <div class="main-area">

        <!-- TOP NAVBAR --------------------------------------------------- -->
        <header class="topbar glass">
            <button class="icon-btn menu-toggle" id="menu-toggle" aria-label="Toggle menu"><i class="ic-menu"></i></button>

            <div class="search-bar">
                <i class="ic-search"></i>
                <input type="text" id="global-search" placeholder="Search prompts, templates...">
            </div>

    
                <div class="topbar-profile" id="topbar-profile">
                    <div class="avatar" id="topbar-avatar"><?= htmlspecialchars($initials) ?></div>
                    <span class="topbar-name"><?= htmlspecialchars($fullname) ?></span>
                </div>
            
        </header>

        <!-- PAGE CONTENT --------------------------------------------------- -->
        <main class="content">

            <!-- ============================= DASHBOARD HOME ============================= -->
            <section class="page active" id="page-dashboard">
                <div class="page-head">
                    <h2>Welcome back, <span class="grad-text"><?= htmlspecialchars($fullname) ?></span> 👋</h2>
                    <p>Here's what's happening with your prompts today.</p>
                </div>

                <div class="stats-grid">
                    <div class="stat-card glass card-hover fade-in">
                        <div class="stat-icon icon-purple"><i class="ic-book"></i></div>
                        <div class="stat-info"><h3 id="stat-total-prompts">0</h3><p>Total Prompts</p></div>
                    </div>
                    <div class="stat-card glass card-hover fade-in">
                        <div class="stat-icon icon-pink"><i class="ic-heart"></i></div>
                        <div class="stat-info"><h3 id="stat-favorites">0</h3><p>Favorites</p></div>
                    </div>
                    <div class="stat-card glass card-hover fade-in">
                        <div class="stat-icon icon-blue"><i class="ic-play"></i></div>
                        <div class="stat-info"><h3 id="stat-tests">0</h3><p>Prompt Tested</p></div>
                    </div>
                    <div class="stat-card glass card-hover fade-in">
                        <div class="stat-icon icon-green"><i class="ic-grid"></i></div>
                        <div class="stat-info"><h3 id="stat-templates">0</h3><p>Templates</p></div>
                    </div>
                </div>

                <div class="dash-grid">
                    <div class="panel glass fade-in activity-panel">
    <h3>
        Prompt Activity
        <span class="muted">(last 7 days)</span>
    </h3>

    <canvas id="chart-activity"></canvas>
</div>
                    <div class="panel glass fade-in">
                        <h3>Quick Actions</h3>
                        <div class="quick-actions">
                            <button class="quick-btn ripple" data-page="generate"><i class="ic-wand"></i>Generate</button>
                            <button class="quick-btn ripple" data-page="improve"><i class="ic-spark"></i>Improve</button>
                            <button class="quick-btn ripple" data-page="test"><i class="ic-play"></i>Test</button>
                            <button class="quick-btn ripple" data-page="library"><i class="ic-book"></i>Library</button>
                        </div>
                    </div>
                </div>

                <div class="dash-grid">
                    <div class="panel glass fade-in">
                        <h3>Recent Prompts</h3>
                        <div id="recent-prompts-list" class="list-scroll"></div>
                    </div>
                    <div class="panel glass fade-in">
                        <h3>Recent Activity</h3>
                        <div id="recent-activity-list" class="list-scroll"></div>
                    </div>
                </div>
            </section>

            <!-- ============================= GENERATE PROMPT ============================= -->
            <section class="page" id="page-generate">
                <div class="page-head">
                    <h2>Generate Prompt</h2>
                    <p>Describe what you need — let Gemini craft the perfect prompt.</p>
                </div>

                <div class="workspace-grid">
                    <div class="panel glass fade-in">
                        <form id="generate-form">
                            <div class="form-group">
                                <label>What do you need a prompt for?</label>
                                <textarea name="topic" rows="6" placeholder="e.g. A prompt to write engaging Instagram captions for a coffee shop..." required></textarea>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Category</label>
                                    <select name="category" id="gen-category"></select>
                                </div>
                                <div class="form-group">
                                    <label>Language</label>
                                    <select name="language">
                                        <option value="Indonesian">Indonesian</option>
                                        <option value="English" selected>English</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Writing Style</label>
                                    <select name="style">
                                        <option>Professional</option>
                                        <option>Friendly</option>
                                        <option>Formal</option>
                                        <option>Creative</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Prompt Length</label>
                                    <select name="length">
                                        <option>Short</option>
                                        <option selected>Medium</option>
                                        <option>Long</option>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block ripple">
                                <i class="ic-wand"></i> Generate Prompt
                            </button>
                        </form>
                    </div>

                    <div class="panel glass fade-in output-panel" id="generate-output-panel">
                        <h3>AI Output</h3>
                        <div class="output-empty" id="generate-empty">
                            <i class="ic-wand big"></i>
                            <p>Your generated prompt will appear here.</p>
                        </div>
                        <div class="output-loading hidden" id="generate-loading">
                            <div class="spinner"></div><p>Talking to Gemini...</p>
                        </div>
                        <div class="output-card hidden" id="generate-result">
                            <div class="output-text" id="generate-text"></div>
                            <div class="output-actions">
                                <button class="btn btn-ghost ripple" data-copy="generate-text"><i class="ic-copy"></i> Copy</button>
                                <button class="btn btn-ghost ripple" id="generate-save-btn"><i class="ic-save"></i> Save</button>
                                <button class="btn btn-ghost ripple" id="generate-improve-btn"><i class="ic-spark"></i> Improve</button>
                                <button class="btn btn-ghost ripple" id="generate-favorite-btn"><i class="ic-heart"></i> Favorite</button>
                                <button class="btn btn-ghost ripple" id="generate-download-btn"><i class="ic-download"></i> Download TXT</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================= IMPROVE PROMPT ============================= -->
            <section class="page" id="page-improve">
                <div class="page-head">
                    <h2>Improve Prompt</h2>
                    <p>Paste an existing prompt and let AI sharpen it.</p>
                </div>

                <div class="workspace-grid">
                    <div class="panel glass fade-in">
                        <form id="improve-form">
                            <div class="form-group">
                                <label>Original Prompt</label>
                                <textarea name="original" id="improve-input" rows="10" placeholder="Paste the prompt you want to improve..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block ripple"><i class="ic-spark"></i> Improve Prompt</button>
                        </form>
                    </div>

                    <div class="panel glass fade-in output-panel">
                        <h3>Improved Result</h3>
                        <div class="output-empty" id="improve-empty">
                            <i class="ic-spark big"></i>
                            <p>Your improved prompt will appear here.</p>
                        </div>
                        <div class="output-loading hidden" id="improve-loading">
                            <div class="spinner"></div><p>Improving with Gemini...</p>
                        </div>
                        <div class="output-card hidden" id="improve-result">
                            <div class="output-text" id="improve-text"></div>
                            <div class="output-actions">
                                <button class="btn btn-ghost ripple" data-copy="improve-text"><i class="ic-copy"></i> Copy</button>
                                <button class="btn btn-ghost ripple" id="improve-save-btn"><i class="ic-save"></i> Save</button>
                                <button class="btn btn-ghost ripple" id="improve-download-btn"><i class="ic-download"></i> Download TXT</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================= TEST PROMPT ============================= -->
            <section class="page" id="page-test">
                <div class="page-head">
                    <h2>Test Prompt</h2>
                    <p>Run any prompt directly against Gemini and see the response.</p>
                </div>

                <div class="workspace-grid">
                    <div class="panel glass fade-in">
                        <form id="test-form">
                            <div class="form-group">
                                <label>Prompt to Test</label>
                                <textarea name="prompt" rows="10" placeholder="Type or paste a prompt to test..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block ripple"><i class="ic-play"></i> Test Prompt</button>
                        </form>
                    </div>

                    <div class="panel glass fade-in output-panel">
                        <h3>AI Response</h3>
                        <div class="output-empty" id="test-empty">
                            <i class="ic-play big"></i>
                            <p>The AI response will appear here.</p>
                        </div>
                        <div class="output-loading hidden" id="test-loading">
                            <div class="spinner"></div><p>Running against Gemini...</p>
                        </div>
                        <div class="output-card hidden" id="test-result">
                            <div class="output-text" id="test-text"></div>
                            <div class="output-actions">
                                <button class="btn btn-ghost ripple" data-copy="test-text"><i class="ic-copy"></i> Copy</button>
                                <button class="btn btn-ghost ripple" id="test-download-btn"><i class="ic-download"></i> Download TXT</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================= PROMPT LIBRARY ============================= -->
            <section class="page" id="page-library">
                <div class="page-head">
                    <h2>Prompt Library</h2>
                    <p>All your saved prompts in one place.</p>
                </div>

                <div class="panel glass fade-in">
                    <div class="library-toolbar">
                        <div class="search-bar small">
                            <i class="ic-search"></i>
                            <input type="text" id="library-search" placeholder="Search prompts...">
                        </div>
                        <select id="library-category-filter"><option value="All">All Categories</option></select>
                    </div>
                    <div class="library-grid" id="library-grid"></div>
                </div>
            </section>

            <!-- ============================= FAVORITES ============================= -->
            <section class="page" id="page-favorites">
                <div class="page-head">
                    <h2>Favorites</h2>
                    <p>Your starred prompts for quick access.</p>
                </div>
                <div class="panel glass fade-in">
                    <div class="library-grid" id="favorites-grid"></div>
                </div>
            </section>

            <!-- ============================= HISTORY ============================= -->
            <section class="page" id="page-history">
                <div class="page-head">
                    <h2>History</h2>
                    <p>A timeline of everything you've done in PromptCraft AI.</p>
                </div>
                <div class="panel glass fade-in">
                    <div class="timeline" id="history-timeline"></div>
                </div>
            </section>

            <!-- ============================= ANALYTICS ============================= -->
            <section class="page" id="page-analytics">
                <div class="page-head">
                    <h2>Analytics</h2>
                    <p>Insights into how you use PromptCraft AI.</p>
                </div>
                <div class="dash-grid">
                    <div class="panel glass fade-in">
                        <h3>Prompts Created (7 Days)</h3>
                        <canvas id="chart-analytics-day" height="220"></canvas>
                    </div>
                    <div class="panel glass fade-in">
                        <h3>Prompts by Category</h3>
                        <canvas id="chart-analytics-category" height="220"></canvas>
                    </div>
                </div>
            </section>

            <!-- ============================= PROFILE ============================= -->
            <section class="page" id="page-profile">
                <div class="page-head">
                    <h2>Profile</h2>
                    <p>Manage your personal information.</p>
                </div>
                <div class="panel glass fade-in profile-panel">
                    <form id="profile-form">
                        <div class="profile-photo-row">
                            <div class="avatar avatar-lg" id="profile-avatar"><?= htmlspecialchars($initials) ?></div>
                            <div>
                                <label class="btn btn-ghost ripple" for="profile-photo-input">Change Photo</label>
                                <input type="file" id="profile-photo-input" accept="image/*" hidden>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="fullname" id="profile-fullname" required>
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" id="profile-email" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>New Password <span class="muted">(leave blank to keep current)</span></label>
                            <input type="password" name="password" placeholder="••••••••">
                        </div>
                        <p class="form-success" id="profile-success"></p>
                        <button type="submit" class="btn btn-primary ripple">Save Changes</button>
                    </form>
                </div>
            </section>

            <!-- ============================= SETTINGS ============================= -->
            <section class="page" id="page-settings">
                <div class="page-head">
                    <h2>Settings</h2>
                    <p>Configure your Gemini API key, theme, and language.</p>
                </div>
                <div class="panel glass fade-in profile-panel">
                    <form id="settings-form">
                        <div class="form-group">
                            <label>Gemini API Key</label>
                            <input type="password" name="api_key" id="settings-api-key" placeholder="Paste your personal Gemini API key (optional)">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Language</label>
                                <select name="language" id="settings-language">
                                    <option value="en">English</option>
                                    <option value="id">Indonesian</option>
                                </select>
                            </div>
                        </div>
                        <p class="form-success" id="settings-success"></p>
                        <button type="submit" class="btn btn-primary ripple">Save Settings</button>
                    </form>
                </div>
            </section>

        </main>
    </div>
</div>

<!-- Toast notification container -->
<div class="toast-container" id="toast-container"></div>

<?php endif; ?>

<script src="script.js"></script>
</body>
</html>