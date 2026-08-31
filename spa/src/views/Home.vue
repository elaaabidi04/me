<script setup>
import { ref } from 'vue'

/* ---- theming --------------------------------------------------------
   Same cycle as every authenticated page (Dashboard.vue etc) -- dusk is
   the default, paper/night are the other two. Cycling this here doubles
   as the homepage's "live demo": the whole page (hero, charts, everything)
   re-colors instantly, so a visitor genuinely sees the real theme system
   rather than a fake isolated widget. */
const themes = ['dusk', 'paper', 'night']
const theme = ref('dusk')
function cycleTheme() {
  theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length]
}
</script>

<template>
  <div class="me home" :data-theme="theme">
    <header class="nav">
      <div class="nav-inner wrap">
        <div class="logo">me</div>
        <nav class="links">
          <a href="#how">How it works</a>
          <a href="#how">See it in action</a>
          <a href="#features">Features</a>
          <a href="#therapists">For therapists</a>
        </nav>
        <div style="display:flex; align-items:center; gap:18px;">
          <button class="ghost-btn" @click="cycleTheme" data-tip="Cycles the app's 3 real themes">◐ theme</button>
          <router-link :to="{ name: 'login' }" class="login-link">Log in</router-link>
          <router-link :to="{ name: 'register' }" class="btn btn-primary btn-sm" data-tip="Opens a blank entry — no signup wall">Start journaling</router-link>
        </div>
      </div>
    </header>

    <main>
      <!-- HERO -->
      <section class="hero">
        <div class="wrap hero-grid">
          <div>
            <span class="eyebrow">Journaling, without the spiral</span>
            <h1>Write every day.<br>Hear from it <em>once a week.</em></h1>
            <p class="lead">A private journal. An AI that notices quietly — and knows when to say nothing.</p>
            <div class="hero-ctas">
              <router-link :to="{ name: 'register' }" class="btn btn-primary" data-tip="Free, 2 minutes, no card needed">Start journaling — it's free</router-link>
              <a href="#how" class="btn btn-ghost" data-tip="Jumps to the 3-step walkthrough">See how it works</a>
            </div>
            <p class="microcopy">No credit card. Encrypted entries. Shared only if you choose to.</p>
          </div>
          <div class="hero-visual">
            <div class="blob"></div>
            <div class="card journal-card">
              <div class="date">Tuesday, 8:42 PM</div>
              <p class="entry-text">"Rough meeting today. Let it go by dinner — that felt new."</p>
              <div class="tags">
                <span class="tag on">stress · work</span>
                <span class="tag">self-doubt</span>
                <span class="tag">recovered quickly</span>
              </div>
            </div>
            <div class="card weekly-card">
              <div class="k">This week</div>
              <p class="msg">Work stress: <b>3 times</b>. Bounced back faster than last week.</p>
            </div>
          </div>
        </div>
      </section>

      <!-- TRUST STRIP -->
      <div class="trust-strip">
        <div class="wrap trust-inner">
          <span>Entries encrypted at rest</span>
          <span>Weekly reflection, not daily pressure</span>
          <span>Nothing diagnostic, ever</span>
          <span>Share only what you consent to</span>
        </div>
      </div>

      <!-- PROBLEM / SOLUTION -->
      <section class="problem">
        <div class="wrap">
          <div class="section-head">
            <span class="kicker">The problem with most journaling apps</span>
            <h2>They want you checking in constantly. We don't.</h2>
            <p>Most apps chase engagement — more prompts, more streaks. Overthinking isn't the goal.</p>
          </div>
          <div class="compare">
            <div class="card card-bad">
              <h3>Typical journaling apps</h3>
              <ul>
                <li>Push daily "insights" to keep you opening the app</li>
                <li>Analyze every entry the moment you write it</li>
                <li>Quietly position the AI as a stand-in therapist</li>
                <li>Reward constant self-monitoring with streaks and badges</li>
              </ul>
            </div>
            <div class="card card-good">
              <h3>me</h3>
              <ul>
                <li>Reflects back on a calm, weekly rhythm — never daily</li>
                <li>Aggregates patterns in code, not a chatbot guessing in the moment</li>
                <li>Stays a companion that notices — never diagnoses, never pathologizes</li>
                <li>Knows when to hold back instead of pushing you to dig deeper</li>
              </ul>
            </div>
          </div>
        </div>
      </section>

      <!-- HOW IT WORKS + LIVE DEMO, merged into one section (2026-08-20:
           "See how it works" used to scroll to a plain 3-step text list,
           with the actual live demo living further down the page where a
           visitor might never scroll. Now the demo is right here, so the
           hero's "See how it works" button shows the real thing, not just
           a description of it. Charts use the page's real --chart-1..5
           vars, so cycling "◐ theme" up top genuinely re-colors them. -->
      <section class="steps" id="how">
        <div class="wrap">
          <div class="section-head">
            <span class="kicker">How it works</span>
            <h2>Three steps. No performance required.</h2>
          </div>
          <div class="steps-grid">
            <div class="step">
              <div class="num">01</div>
              <h3>Write, whenever</h3>
              <p>No prompts to perform. "Whatever's here" is enough.</p>
            </div>
            <div class="step">
              <div class="num">02</div>
              <h3>Get a weekly reflection</h3>
              <p>Recurring feelings and triggers, described plainly — never a verdict.</p>
            </div>
            <div class="step">
              <div class="num">03</div>
              <h3>Share, only if you want to</h3>
              <p>Send a chosen summary to your therapist, element by element, with your consent.</p>
            </div>
          </div>

          <div class="demo-shell">
            <div class="demo-toolbar">
              <span class="demo-label">step 02 in action — try "◐ theme" up top, this re-colors too</span>
            </div>
            <div class="demo-grid">
              <div class="demo-card">
                <div class="demo-card-title">This week's emotions</div>
                <svg viewBox="0 0 260 130" class="bar-chart">
                  <g class="bars">
                    <rect x="10"  y="30" width="26" height="90" rx="6" style="fill:var(--chart-1)"/>
                    <rect x="56"  y="55" width="26" height="65" rx="6" style="fill:var(--chart-2)"/>
                    <rect x="102" y="15" width="26" height="105" rx="6" style="fill:var(--chart-3)"/>
                    <rect x="148" y="70" width="26" height="50" rx="6" style="fill:var(--chart-4)"/>
                    <rect x="194" y="40" width="26" height="80" rx="6" style="fill:var(--chart-5)"/>
                  </g>
                  <g class="labels" font-family="Instrument Sans, sans-serif" font-size="10" fill="var(--muted)">
                    <text x="23"  y="128" text-anchor="middle">sad</text>
                    <text x="69"  y="128" text-anchor="middle">calm</text>
                    <text x="115" y="128" text-anchor="middle">doubt</text>
                    <text x="161" y="128" text-anchor="middle">joy</text>
                    <text x="207" y="128" text-anchor="middle">stress</text>
                  </g>
                </svg>
              </div>
              <div class="demo-card demo-card-donut">
                <div class="demo-card-title">Top triggers</div>
                <div class="donut-wrap">
                  <div class="donut"></div>
                  <div class="donut-center"><b>26</b><span>moments</span></div>
                </div>
                <ul class="donut-legend">
                  <li><i style="background:var(--chart-1)"></i>work · 42%</li>
                  <li><i style="background:var(--chart-2)"></i>self-doubt · 31%</li>
                  <li><i style="background:var(--chart-3)"></i>sleep · 17%</li>
                  <li><i style="background:var(--chart-4)"></i>other · 10%</li>
                </ul>
              </div>
            </div>
            <div class="demo-foot">
              <router-link :to="{ name: 'register' }" class="btn btn-primary btn-sm" data-tip="Free, 2 minutes, no card needed">Start journaling — it's free</router-link>
            </div>
          </div>
        </div>
      </section>

      <!-- FEATURES -->
      <section id="features">
        <div class="wrap">
          <div class="section-head">
            <span class="kicker">What's inside</span>
            <h2>Built to notice, not to nag</h2>
            <p>What a calm companion actually does — not what maximizes screen time.</p>
          </div>
          <div class="features-grid">
            <div class="feature">
              <div class="icon">✎</div>
              <h3>Daily journaling</h3>
              <p>A distraction-free space to write, with a calendar of your history.</p>
            </div>
            <div class="feature">
              <div class="icon">◐</div>
              <h3>Pattern recognition</h3>
              <p>Emotions and triggers extracted per entry, aggregated over time.</p>
            </div>
            <div class="feature">
              <div class="icon">✧</div>
              <h3>A coach that holds back</h3>
              <p>Five response modes — including knowing when to say nothing.</p>
            </div>
            <div class="feature">
              <div class="icon">✓</div>
              <h3>Corrigible, always</h3>
              <p>Correct anything in one tap. It updates every future reflection.</p>
            </div>
            <div class="feature">
              <div class="icon">⚷</div>
              <h3>Private by default</h3>
              <p>Solo mode, encrypted entries. Nothing leaves your account uninvited.</p>
            </div>
            <div class="feature">
              <div class="icon">☾</div>
              <h3>A gentle safety net</h3>
              <p>Real distress is routed to real human support, always.</p>
            </div>
          </div>
        </div>
      </section>

      <!-- FOR THERAPISTS -->
      <section id="therapists">
        <div class="wrap">
          <div class="therapist-band">
            <div>
              <span class="kicker">For therapists</span>
              <h2>A head start, not a replacement.</h2>
              <p>See only what your client explicitly shares — summaries, flagged patterns, a crisis channel. Nothing automatic.</p>
              <router-link :to="{ name: 'register', query: { role: 'therapist' } }" class="btn btn-primary" data-tip="Opens signup with the therapist role pre-selected">Join as a therapist</router-link>
            </div>
            <div class="therapist-mini">
              <div class="row"><span class="dot"></span> Client shared this week's reflection — 2 hours ago</div>
              <div class="row"><span class="dot"></span> Sadness trending up vs. last 6 weeks</div>
              <div class="row"><span class="dot"></span> New pattern flagged: self-doubt → withdrawal</div>
            </div>
          </div>
        </div>
      </section>

      <!-- QUOTES (placeholder — swap for real user quotes before launch) -->
      <section>
        <div class="wrap">
          <div class="section-head">
            <span class="kicker">What people are noticing</span>
            <h2>Illustrative feedback — replace with real quotes at launch</h2>
          </div>
          <div class="quotes-grid">
            <div class="quote-card">
              <p class="q">"The first app that didn't make me feel worse for not journaling every single day."</p>
              <div class="who"><div class="avatar"></div> Early tester, placeholder quote</div>
            </div>
            <div class="quote-card">
              <p class="q">"My weekly reflection actually reads like something a thoughtful friend would say — not a report."</p>
              <div class="who"><div class="avatar"></div> Early tester, placeholder quote</div>
            </div>
            <div class="quote-card">
              <p class="q">"Sharing one weekly summary with my therapist saved us the first ten minutes of every session."</p>
              <div class="who"><div class="avatar"></div> Early tester, placeholder quote</div>
            </div>
          </div>
        </div>
      </section>

      <!-- PRIVACY -->
      <section id="trust">
        <div class="wrap">
          <div class="section-head">
            <span class="kicker">Trust</span>
            <h2>Your words stay yours</h2>
          </div>
          <div class="privacy">
            <div class="item"><div class="big">Encrypted</div><p>Journal entries are encrypted at rest, always.</p></div>
            <div class="item"><div class="big">Non-diagnostic</div><p>me never assigns a diagnosis or a disorder — it describes, it doesn't decide.</p></div>
            <div class="item"><div class="big">Consent, element by element</div><p>Every share with a therapist is explicit and revocable — nothing is automatic.</p></div>
            <div class="item"><div class="big">You can delete anytime</div><p>Remove any entry, correction, or shared item permanently, whenever you choose.</p></div>
          </div>
        </div>
      </section>

      <!-- FINAL CTA -->
      <section>
        <div class="wrap">
          <div class="final-cta">
            <h2>Start with today's entry.</h2>
            <p>No streaks. No daily pressure. Just a place to write.</p>
            <router-link :to="{ name: 'register' }" class="btn btn-primary" data-tip="Free, no credit card">Start journaling — it's free</router-link>
          </div>
        </div>
      </section>
    </main>

    <footer>
      <div class="wrap">
        <div class="footer-grid">
          <div>
            <div class="logo">me</div>
            <p>A private journal with a calm AI companion — and an optional, consent-based bridge to your therapist.</p>
          </div>
          <div class="footer-col">
            <h4>Product</h4>
            <a href="#features">Features</a>
            <a href="#how">How it works</a>
            <a href="#trust">Privacy</a>
          </div>
          <div class="footer-col">
            <h4>For</h4>
            <router-link :to="{ name: 'register' }">Individuals</router-link>
            <a href="#therapists">Therapists</a>
          </div>
          <div class="footer-col">
            <h4>Account</h4>
            <router-link :to="{ name: 'login' }">Log in</router-link>
            <router-link :to="{ name: 'register' }">Sign up</router-link>
          </div>
        </div>
        <div class="footer-bottom">
          <span>© 2026 me. Not a substitute for professional mental health care.</span>
          <span>Made with care, on a calm rhythm.</span>
        </div>
      </div>
    </footer>
  </div>
</template>

<style scoped>
/* Same palette + variable names as Dashboard.vue's .me[data-theme] blocks
   (kept in sync by eye -- see the CHART PALETTE comment there). Vue scoped
   styles don't leak between components, so Home.vue needs its own copy of
   these custom-property definitions rather than relying on Dashboard.vue's;
   without this block every var(--accent)/var(--paper)/var(--chart-N) below
   resolves to nothing, which is why the page rendered flat and colorless. */
.home[data-theme='dusk']{
  --paper:#F1F2F0; --surface:#FBFBFA; --ink:#26282B; --muted:#6B6F72;
  --accent:#7FA08A; --accent-deep:#57735F; --ochre:#C6A063; --line:#E3E4E1;
  --chart-1:#7FA08A; --chart-2:#C6A063; --chart-3:#6E8CA6; --chart-4:#B97159; --chart-5:#8C7093;
}
.home[data-theme='paper']{
  --paper:#F7F4EE; --surface:#FFFFFF; --ink:#33302A; --muted:#7A736A;
  --accent:#9A8C74; --accent-deep:#6E624E; --ochre:#B98B54; --line:#EAE5DC;
  --chart-1:#9A8C74; --chart-2:#B98B54; --chart-3:#6E8CA6; --chart-4:#B97159; --chart-5:#8C7093;
}
.home[data-theme='night']{
  --paper:#1B1D20; --surface:#232629; --ink:#E9E9E6; --muted:#9BA0A2;
  --accent:#89AE97; --accent-deep:#B6D0BF; --ochre:#D3AE74; --line:#31353A;
  --chart-1:#89AE97; --chart-2:#D3AE74; --chart-3:#86AEC9; --chart-4:#D48F76; --chart-5:#AD93B5;
}
.home{
  --font-display:'Fraunces', Georgia, serif;
  --font-ui:'Instrument Sans', system-ui, -apple-system, sans-serif;
  font-family:var(--font-ui); line-height:1.6; background:var(--paper); color:var(--ink);
  min-height:100vh; transition:background .4s ease, color .4s ease;
}
.home *,.home *::before,.home *::after{box-sizing:border-box;}
.home a{color:inherit;}
.home img,.home svg{display:block; max-width:100%;}
.home .wrap{max-width:1120px; margin:0 auto; padding:0 28px;}
.home section{padding:88px 0;}
.home h1,.home h2,.home h3{font-family:var(--font-display); font-weight:600; line-height:1.15; margin:0;}
.home p{margin:0;}

/* ---------- nav ---------- */
.home header.nav{
  position:sticky; top:0; z-index:50; background:var(--paper);
  border-bottom:1px solid var(--line); transition:background .4s ease, border-color .4s ease;
}
.home .nav-inner{
  padding:18px 28px; display:flex; align-items:center; justify-content:space-between;
}
.home .logo{font-family:var(--font-display); font-size:22px; font-weight:600; letter-spacing:-.02em;}
.home .logo::before{content:"●"; color:var(--accent); font-size:11px; vertical-align:middle; margin-right:8px;}
.home nav.links{display:flex; align-items:center; gap:32px;}
.home nav.links a{font-size:14.5px; color:var(--muted); text-decoration:none; font-weight:500; transition:color .2s ease;}
.home nav.links a:hover{color:var(--ink);}
.home .ghost-btn{
  background:transparent; border:1px solid var(--line); color:var(--muted); border-radius:999px;
  padding:8px 14px; font-size:13px; font-weight:600; cursor:pointer; transition:border-color .2s ease, color .2s ease;
}
.home .ghost-btn:hover{border-color:var(--accent); color:var(--ink);}
.home .btn{
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:11px 22px; border-radius:999px; font-size:14.5px; font-weight:600;
  text-decoration:none; cursor:pointer; border:1px solid transparent;
  transition:transform .15s ease, box-shadow .15s ease, background .2s ease, border-color .2s ease;
  white-space:nowrap;
}
.home .btn:hover{transform:translateY(-1px);}
.home .btn-primary{background:var(--accent-deep); color:var(--surface);}
.home .btn-primary:hover{box-shadow:0 8px 20px -8px rgba(87,115,95,.55);}
.home .btn-ghost{background:transparent; border-color:var(--line); color:var(--ink);}
.home .btn-ghost:hover{border-color:var(--accent);}
.home .btn-sm{padding:9px 18px; font-size:13.5px;}
.home .login-link{font-size:14.5px; font-weight:500; color:var(--muted); text-decoration:none;}
.home .login-link:hover{color:var(--ink);}

/* ---------- hero ---------- */
.home .hero{padding:80px 0 70px;}
.home .hero-grid{display:grid; grid-template-columns:1.05fr .95fr; gap:56px; align-items:center;}
.home .eyebrow{
  display:inline-flex; align-items:center; gap:8px; padding:6px 14px;
  border:1px solid var(--line); border-radius:999px; font-size:13px;
  font-weight:600; color:var(--accent-deep); background:var(--surface); margin-bottom:22px;
}
.home .eyebrow::before{content:"✦"; color:var(--ochre);}
.home .hero h1{font-size:52px; letter-spacing:-.02em; margin-bottom:22px;}
.home .hero h1 em{font-style:italic; color:var(--accent-deep);}
.home .hero p.lead{font-size:18px; color:var(--muted); max-width:480px; margin-bottom:34px;}
.home .hero-ctas{display:flex; align-items:center; gap:20px; flex-wrap:wrap;}
.home .hero .btn-primary{padding:14px 26px; font-size:15.5px;}
.home .microcopy{font-size:13px; color:var(--muted); margin-top:16px;}

.home .hero-visual{position:relative;}
.home .card{
  background:var(--surface); border:1px solid var(--line); border-radius:18px;
  padding:26px; box-shadow:0 20px 50px -24px rgba(38,40,43,.25);
  transition:background .4s ease, border-color .4s ease;
}
@keyframes floatCard{
  0%,100%{ transform: translateY(0) rotate(var(--tilt,0deg)); }
  50%{ transform: translateY(-9px) rotate(var(--tilt,0deg)); }
}
@keyframes floatChip{
  0%,100%{ transform: translateY(0); }
  50%{ transform: translateY(-4px); }
}
.home .journal-card{
  --tilt:-2.5deg; transform:rotate(-2.5deg); position:relative; z-index:2;
  animation: floatCard 6s ease-in-out infinite;
}
.home .journal-card .date{font-size:12.5px; color:var(--muted); font-weight:600; letter-spacing:.03em; text-transform:uppercase;}
.home .journal-card .entry-text{font-family:var(--font-display); font-size:16px; color:var(--ink); margin-top:12px; line-height:1.5;}
.home .journal-card .tags{display:flex; gap:8px; margin-top:18px; flex-wrap:wrap;}
.home .tag{
  font-size:12px; padding:5px 11px; border-radius:999px; background:var(--paper); color:var(--muted);
  border:1px solid var(--line); display:inline-block; animation: floatChip 4s ease-in-out infinite;
}
.home .tag:nth-child(1){ animation-duration:4s; animation-delay:0s; }
.home .tag:nth-child(2){ animation-duration:4.7s; animation-delay:.4s; }
.home .tag:nth-child(3){ animation-duration:5.3s; animation-delay:.8s; }
.home .tag.on{background:color-mix(in srgb, var(--accent) 16%, transparent); color:var(--accent-deep); border-color:transparent; font-weight:600;}

.home .weekly-card{
  --tilt:3deg; position:absolute; right:-30px; bottom:-38px; width:220px;
  transform:rotate(3deg); z-index:3; animation: floatCard 5s ease-in-out infinite; animation-delay:.6s;
}
.home .weekly-card .k{font-size:12px; font-weight:700; color:var(--accent-deep); text-transform:uppercase; letter-spacing:.04em;}
.home .weekly-card .msg{font-size:13.5px; margin-top:8px; color:var(--muted); line-height:1.5;}
.home .weekly-card .msg b{color:var(--ink);}
@media (prefers-reduced-motion: reduce){
  .home .journal-card,.home .weekly-card,.home .tag{ animation:none !important; }
}

.home .blob{
  position:absolute; width:340px; height:340px; border-radius:50%;
  background:radial-gradient(circle at 30% 30%, color-mix(in srgb, var(--accent) 30%, transparent), transparent 70%);
  top:-40px; right:-60px; z-index:1;
}

/* ---------- trust strip ---------- */
.home .trust-strip{padding:34px 0; border-top:1px solid var(--line); border-bottom:1px solid var(--line);}
.home .trust-inner{display:flex; align-items:center; justify-content:center; gap:44px; flex-wrap:wrap; font-size:13.5px; color:var(--muted); font-weight:500;}
.home .trust-inner span{display:flex; align-items:center; gap:8px;}
.home .trust-inner span::before{content:"✓"; color:var(--accent-deep); font-weight:700;}

/* ---------- problem / solution ---------- */
.home .section-head{max-width:620px; margin:0 auto 56px; text-align:center;}
.home .kicker{
  display:block; font-size:13px; font-weight:700; letter-spacing:.08em; text-transform:uppercase;
  color:var(--ochre); margin-bottom:14px;
}
.home .section-head h2{font-size:36px; letter-spacing:-.01em;}
.home .section-head p{color:var(--muted); font-size:16.5px; margin-top:16px;}

.home .compare{display:grid; grid-template-columns:1fr 1fr; gap:24px;}
.home .compare .card{padding:32px;}
.home .compare .card h3{font-size:19px; margin-bottom:18px; display:flex; align-items:center; gap:10px;}
.home .compare .card ul{list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:14px;}
.home .compare .card li{font-size:15px; color:var(--muted); display:flex; gap:12px; align-items:flex-start;}
.home .card-bad{background:var(--surface); border-color:var(--line);}
.home .card-bad h3::before{content:"✕"; color:#B97159; font-weight:700;}
.home .card-bad li::before{content:"—"; color:var(--line); font-weight:700;}
.home .card-good{background:var(--accent-deep); border-color:var(--accent-deep); color:var(--surface);}
.home .card-good h3{color:var(--surface);}
.home .card-good h3::before{content:"✓"; color:var(--ochre); font-weight:700;}
.home .compare .card-good li{color:#FFFFFF;}
.home .card-good li::before{content:"✓"; color:var(--ochre); font-weight:700;}

/* ---------- how it works ---------- */
.home .steps{background:var(--surface); border-top:1px solid var(--line); border-bottom:1px solid var(--line); transition:background .4s ease, border-color .4s ease;}
.home .steps-grid{display:grid; grid-template-columns:repeat(3,1fr); gap:36px;}
.home .step{position:relative; padding-top:8px;}
.home .step .num{font-family:var(--font-display); font-size:44px; color:var(--line); font-weight:600; margin-bottom:10px;}
.home .step h3{font-size:19px; margin-bottom:10px;}
.home .step p{color:var(--muted); font-size:15px;}

/* ---------- live demo ---------- */
.home .demo-shell{
  background:var(--surface); border:1px solid var(--line); border-radius:22px; padding:30px;
  transition:background .4s ease, border-color .4s ease;
}
.home .demo-toolbar{display:flex; align-items:center; justify-content:center; margin-bottom:20px;}
.home .demo-label{font-size:12.5px; color:var(--muted); font-weight:600; text-align:center;}
.home .demo-grid{display:grid; grid-template-columns:1.3fr 1fr; gap:20px;}
.home .demo-card{
  background:var(--paper); border:1px solid var(--line); border-radius:16px; padding:22px;
  transition:background .4s ease, border-color .4s ease;
}
.home .demo-card-title{font-size:13px; font-weight:700; color:var(--ink); margin-bottom:14px;}
.home .bar-chart{width:100%; height:auto;}
.home .bar-chart rect{transition:fill .4s ease;}
.home .donut-wrap{position:relative; width:120px; height:120px; margin:0 auto 18px;}
.home .donut{
  width:120px; height:120px; border-radius:50%;
  background:conic-gradient(var(--chart-1) 0% 42%, var(--chart-2) 42% 73%, var(--chart-3) 73% 90%, var(--chart-4) 90% 100%);
  transition:background .4s ease;
}
.home .donut-center{
  position:absolute; inset:22px; border-radius:50%; background:var(--paper);
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  transition:background .4s ease;
}
.home .donut-center b{font-family:var(--font-display); font-size:20px; color:var(--ink);}
.home .donut-center span{font-size:10px; color:var(--muted);}
.home .donut-legend{list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:9px;}
.home .donut-legend li{display:flex; align-items:center; gap:9px; font-size:12.5px; color:var(--muted);}
.home .donut-legend i{width:9px; height:9px; border-radius:50%; flex:0 0 auto;}
.home .demo-foot{display:flex; justify-content:center; margin-top:24px;}

/* ---------- features ---------- */
.home .features-grid{display:grid; grid-template-columns:repeat(3,1fr); gap:22px;}
.home .feature{
  background:var(--surface); border:1px solid var(--line); border-radius:16px; padding:28px;
  transition:border-color .2s ease, transform .2s ease, background .4s ease;
}
.home .feature:hover{border-color:var(--accent); transform:translateY(-2px);}
.home .feature .icon{
  width:42px; height:42px; border-radius:11px; background:color-mix(in srgb, var(--accent) 18%, transparent);
  display:flex; align-items:center; justify-content:center; margin-bottom:16px; font-size:19px;
}
.home .feature h3{font-size:16.5px; margin-bottom:8px;}
.home .feature p{font-size:14.5px; color:var(--muted);}

/* ---------- for therapists ---------- */
.home .therapist-band{
  background:var(--ink); color:var(--surface); border-radius:24px; padding:56px;
  display:grid; grid-template-columns:1.1fr .9fr; gap:40px; align-items:center;
  transition:background .4s ease;
}
.home .therapist-band .kicker{color:var(--ochre);}
.home .therapist-band h2{font-size:30px; color:var(--surface); margin-bottom:16px;}
.home .therapist-band p{color:rgba(251,251,250,.72); font-size:15.5px; margin-bottom:26px; max-width:460px;}
.home .therapist-band .btn-primary{background:var(--ochre); color:var(--ink);}
.home .therapist-mini{display:flex; flex-direction:column; gap:14px;}
.home .therapist-mini .row{
  display:flex; align-items:center; gap:12px; background:rgba(251,251,250,.06);
  border:1px solid rgba(251,251,250,.14); border-radius:12px; padding:14px 16px; font-size:13.5px;
}
.home .therapist-mini .row .dot{width:8px; height:8px; border-radius:50%; background:var(--accent); flex:0 0 auto;}

/* ---------- quotes ---------- */
.home .quotes-grid{display:grid; grid-template-columns:repeat(3,1fr); gap:22px;}
.home .quote-card{background:var(--surface); border:1px solid var(--line); border-radius:16px; padding:26px; transition:background .4s ease, border-color .4s ease;}
.home .quote-card p.q{font-family:var(--font-display); font-size:16.5px; font-style:italic; line-height:1.5; margin-bottom:18px;}
.home .quote-card .who{display:flex; align-items:center; gap:10px; font-size:13px; color:var(--muted);}
.home .avatar{width:30px; height:30px; border-radius:50%; background:var(--accent); opacity:.7;}

/* ---------- privacy ---------- */
.home .privacy{display:grid; grid-template-columns:repeat(4,1fr); gap:22px; text-align:center;}
.home .privacy .item{padding:8px;}
.home .privacy .item .big{font-family:var(--font-display); font-size:15px; font-weight:600; margin-bottom:6px;}
.home .privacy .item p{font-size:13.5px; color:var(--muted);}

/* ---------- final cta ---------- */
.home .final-cta{
  text-align:center; background:var(--surface);
  border:1px solid var(--line); border-radius:24px; padding:72px 40px;
  transition:background .4s ease, border-color .4s ease;
}
.home .final-cta h2{font-size:36px; margin-bottom:16px;}
.home .final-cta p{color:var(--muted); font-size:16px; margin-bottom:30px;}

/* ---------- footer ---------- */
.home footer{border-top:1px solid var(--line); padding:48px 0 32px;}
.home .footer-grid{display:grid; grid-template-columns:1.4fr repeat(3,1fr); gap:32px; margin-bottom:36px;}
.home .footer-grid .logo{margin-bottom:10px;}
.home .footer-grid p{color:var(--muted); font-size:14px; max-width:260px;}
.home .footer-col h4{font-size:13px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); margin-bottom:14px; font-weight:700;}
.home .footer-col a{display:block; font-size:14px; color:var(--ink); text-decoration:none; margin-bottom:10px; opacity:.85;}
.home .footer-col a:hover{opacity:1; color:var(--accent-deep);}
.home .footer-bottom{
  display:flex; justify-content:space-between; align-items:center; padding-top:24px;
  border-top:1px solid var(--line); font-size:13px; color:var(--muted); flex-wrap:wrap; gap:12px;
}

/* ---------- button tooltips ---------- */
.home [data-tip]{position:relative;}
.home [data-tip]::after{
  content:attr(data-tip); position:absolute; bottom:calc(100% + 10px); left:50%;
  transform:translateX(-50%) translateY(4px);
  background:var(--ink); color:var(--surface); font-size:12px; font-weight:500;
  padding:7px 12px; border-radius:8px; white-space:nowrap; z-index:20;
  opacity:0; pointer-events:none; transition:opacity .16s ease, transform .16s ease;
  box-shadow:0 10px 24px -10px rgba(0,0,0,.4);
}
.home [data-tip]::before{
  content:""; position:absolute; bottom:calc(100% + 5px); left:50%; transform:translateX(-50%);
  border:5px solid transparent; border-top-color:var(--ink); z-index:20;
  opacity:0; transition:opacity .16s ease; pointer-events:none;
}
.home [data-tip]:hover::after,.home [data-tip]:focus-visible::after{opacity:1; transform:translateX(-50%) translateY(0);}
.home [data-tip]:hover::before,.home [data-tip]:focus-visible::before{opacity:1;}

@media (max-width:860px){
  .home nav.links{display:none;}
  .home .hero-grid{grid-template-columns:1fr;}
  .home .hero h1{font-size:38px;}
  .home .compare{grid-template-columns:1fr;}
  .home .steps-grid{grid-template-columns:1fr;}
  .home .demo-grid{grid-template-columns:1fr;}
  .home .features-grid{grid-template-columns:1fr 1fr;}
  .home .quotes-grid{grid-template-columns:1fr;}
  .home .privacy{grid-template-columns:1fr 1fr;}
  .home .therapist-band{grid-template-columns:1fr; padding:36px 24px;}
  .home .footer-grid{grid-template-columns:1fr 1fr;}
  .home .weekly-card{position:static; margin-top:18px; transform:rotate(0); width:100%;}
  .home .blob{display:none;}
}
</style>
