<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="GPCCI HRIS — a clear, connected way to manage people information and everyday HR processes.">

        <title>GPCCI HRIS | Human Resource Information System</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
        <script>
            const savedTheme = localStorage.getItem('gpcci-theme');
            document.documentElement.dataset.theme = savedTheme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <main class="site-shell">
            <nav class="topbar" aria-label="Main navigation">
                <a class="brand" href="/" aria-label="GPCCI HRIS home">
                    <img class="brand-logo" src="{{ asset('images/gpcci-ahk-logo.png') }}" alt="GPCCI AHK German-Philippine Chamber of Commerce and Industry">
                </a>

                <div class="nav-links">
                    <a href="#why-hris">Why HRIS</a>
                    <a href="#built-for-people">Built for people</a>
                </div>

                <div class="nav-actions">
                    <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode">
                        <svg class="theme-icon theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.2 15.3A8.5 8.5 0 0 1 8.7 3.8 8.5 8.5 0 1 0 20.2 15.3Z"/></svg>
                        <svg class="theme-icon theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
                    </button>
                    <a class="nav-action" href="#get-started">Get started <span aria-hidden="true">↗</span></a>
                </div>
            </nav>

            <div class="announcement"><span>✦</span> People operations, thoughtfully connected <a href="#why-hris">Discover HRIS <b>↗</b></a></div>

            <section class="hero" aria-labelledby="hero-title">
                <div class="hero-copy">
                    <p class="eyebrow"><span class="eyebrow-dot"></span> GPCCI · People & operations</p>
                    <h1 id="hero-title" tabindex="0">Human Resource<br><em>Information System</em></h1>
                    <p class="hero-intro">A more connected way to support your people. Bring essential HR information and everyday processes together in one clear, dependable place.</p>
                    <div class="hero-actions">
                        <a class="button button-primary" href="#get-started">Get started <span aria-hidden="true">↗</span></a>
                        <a class="text-link" href="#built-for-people">Explore HRIS <span aria-hidden="true">↓</span></a>
                    </div>
                    <p class="hero-note"><span class="note-check">✓</span> Designed for clarity, built around people</p>
                </div>

                <div class="hero-visual" aria-label="HRIS dashboard preview">
                    <div class="orbit orbit-one"></div>
                    <div class="orbit orbit-two"></div>
                    <div class="dashboard-card">
                        <div class="dashboard-top">
                            <div>
                                <span class="card-kicker"><i aria-hidden="true">G</i> GPCCI HRIS <b>PREVIEW</b></span>
                                <h2>Your people, at a glance</h2>
                            </div>
                            <span class="avatar" aria-label="HR team">HR</span>
                        </div>
                        <div class="metric-grid">
                            <div class="metric-card">
                                <span class="metric-icon blue">↗</span>
                                <small>Team members</small>
                                <strong>128</strong>
                                <span class="metric-change"><i aria-hidden="true">↗</i> Sample view</span>
                            </div>
                            <div class="metric-card">
                                <span class="metric-icon gold">◷</span>
                                <small>Requests to review</small>
                                <strong>06</strong>
                                <span class="metric-change pending"><i aria-hidden="true">•</i> Sample view</span>
                            </div>
                        </div>
                        <div class="chart-card">
                            <div class="chart-heading"><span><strong>Team overview</strong><small>Illustrative trend</small></span><b class="chart-period">This year <i aria-hidden="true">⌄</i></b></div>
                            <div class="chart">
                                <div class="chart-bars"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
                                <svg class="chart-trend" viewBox="0 0 400 100" preserveAspectRatio="none" aria-hidden="true">
                                    <defs>
                                        <linearGradient id="trend-fill" x1="0" x2="0" y1="0" y2="1">
                                            <stop offset="0%" stop-color="#76aaba" stop-opacity=".22" />
                                            <stop offset="100%" stop-color="#76aaba" stop-opacity="0" />
                                        </linearGradient>
                                    </defs>
                                    <path class="trend-area" d="M0 76 C25 70 35 48 60 52 S96 68 120 48 S156 30 180 38 S215 50 240 28 S276 34 300 18 S345 25 400 8 V100 H0 Z" />
                                    <path class="trend-stroke" d="M0 76 C25 70 35 48 60 52 S96 68 120 48 S156 30 180 38 S215 50 240 28 S276 34 300 18 S345 25 400 8" />
                                    <circle class="trend-point" cx="300" cy="18" r="4" />
                                </svg>
                            </div>
                            <div class="chart-labels"><span>Jan</span><span>Apr</span><span>Jul</span><span>Oct</span></div>
                        </div>
                        <div class="status-row"><span><i></i> Example interface · Sample data</span></div>
                    </div>
                </div>
            </section>

            <section class="impact-strip" aria-label="GPCCI HRIS benefits">
                <div class="impact-intro"><span class="impact-star">✦</span><span>One system.<br><strong>Many possibilities.</strong></span></div>
                <div class="impact-item"><strong>01</strong><span>People-first<br>operations</span></div>
                <div class="impact-item"><strong>02</strong><span>Clearer<br>decisions</span></div>
                <div class="impact-item"><strong>03</strong><span>Stronger<br>organizations</span></div>
            </section>

            <section class="trust-strip" aria-label="GPCCI mission">
                <p><span class="line"></span> Built with the spirit of</p>
                <strong>German–Philippine partnership</strong>
                <p class="trust-description">Supporting the people and organizations that move business forward.</p>
            </section>

            <section class="capabilities-section" id="built-for-people" aria-labelledby="capabilities-title">
                <div class="capabilities-heading">
                    <p class="eyebrow"><span class="eyebrow-dot"></span> Everything in one place</p>
                    <h2 id="capabilities-title">The calm behind<br><em>great people work.</em></h2>
                    <p>From the first day to every day after, GPCCI HRIS gives your organization a clearer way to move forward.</p>
                    <a class="text-link" href="#get-started">See what’s possible <span aria-hidden="true">↗</span></a>
                </div>
                <div class="capability-grid">
                    <article class="capability-card capability-card-wide">
                        <span class="capability-number">01</span>
                        <div class="capability-icon icon-people"><i></i><i></i><i></i></div>
                        <h3>Employee records</h3>
                        <p>One trusted home for the information that keeps your team moving.</p>
                        <span class="card-arrow">↗</span>
                    </article>
                    <article class="capability-card capability-card-gold">
                        <span class="capability-number">02</span>
                        <div class="capability-icon icon-time">◷</div>
                        <h3>Leave & attendance</h3>
                        <p>Make requests and approvals feel simple for everyone.</p>
                        <span class="card-arrow">↗</span>
                    </article>
                    <article class="capability-card capability-card-blue">
                        <span class="capability-number">03</span>
                        <div class="capability-icon icon-growth">↗</div>
                        <h3>Growth insights</h3>
                        <p>See the patterns that help your organization grow with intention.</p>
                        <span class="card-arrow">↗</span>
                    </article>
                </div>
            </section>

            <section class="value-section" id="why-hris" aria-labelledby="value-title">
                <div class="section-heading">
                    <p class="eyebrow">A better way to work</p>
                    <h2 id="value-title">Less admin.<br><em>More impact.</em></h2>
                </div>
                <div class="value-content">
                    <p>GPCCI HRIS brings the essentials into one thoughtful place, so your HR team can spend less time chasing information and more time building a workplace where people can do their best work.</p>
                    <div class="feature-list">
                        <div class="feature-item"><span>01</span><div><h3>One clear source of truth</h3><p>Keep employee information organized, accessible, and ready when you need it.</p></div></div>
                        <div class="feature-item"><span>02</span><div><h3>Built around your people</h3><p>Make everyday HR moments simpler for employees, managers, and administrators.</p></div></div>
                        <div class="feature-item"><span>03</span><div><h3>Ready to grow with you</h3><p>A reliable foundation for the next chapter of your organization.</p></div></div>
                    </div>
                </div>
            </section>

            <section class="cta-section" id="get-started" aria-labelledby="cta-title">
                <div>
                    <p class="eyebrow">Your next chapter starts here</p>
                    <h2 id="cta-title">Make room for<br><em>what matters.</em></h2>
                </div>
                <div class="cta-side">
                    <p>We’re preparing a better way to manage your people and your work. GPCCI HRIS is coming soon.</p>
                    <a class="button button-light" href="#get-started">Get started <span aria-hidden="true">↗</span></a>
                </div>
            </section>

            <footer class="footer">
                <a class="brand" href="/" aria-label="GPCCI HRIS home"><img class="brand-logo" src="{{ asset('images/gpcci-ahk-logo.png') }}" alt="GPCCI AHK German-Philippine Chamber of Commerce and Industry"></a>
                <p>German Philippine Chamber of Commerce and Industry</p>
                <span>© {{ date('Y') }} GPCCI HRIS</span>
            </footer>
        </main>
    </body>
</html>
