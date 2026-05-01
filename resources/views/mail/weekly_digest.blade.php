<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Weekly Chess Progress</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f1117; color: #e2e8f0; margin: 0; padding: 20px; }
  .container { max-width: 600px; margin: 0 auto; background: #1a1d2e; border-radius: 12px; overflow: hidden; }
  .header { background: linear-gradient(135deg, #10b981, #059669); padding: 30px; text-align: center; }
  .header h1 { margin: 0; color: #fff; font-size: 24px; }
  .header p { margin: 8px 0 0; color: rgba(255,255,255,0.8); font-size: 14px; }
  .body { padding: 30px; }
  .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 20px 0; }
  .stat-card { background: #0f1117; border-radius: 8px; padding: 16px; text-align: center; }
  .stat-value { font-size: 28px; font-weight: bold; color: #10b981; font-variant-numeric: tabular-nums; }
  .stat-label { font-size: 12px; color: #94a3b8; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.05em; }
  .stat-delta { font-size: 13px; margin-top: 4px; }
  .delta-pos { color: #10b981; }
  .delta-neg { color: #ef4444; }
  .section { margin-top: 24px; }
  .section h2 { font-size: 16px; color: #e2e8f0; margin-bottom: 12px; }
  .pill { display: inline-block; background: #ef4444; color: #fff; border-radius: 20px; padding: 4px 12px; font-size: 13px; font-weight: 600; }
  .pill.theme { background: #f59e0b; }
  .cta { display: block; text-align: center; background: #10b981; color: #fff; text-decoration: none; padding: 14px 24px; border-radius: 8px; font-weight: 600; margin-top: 24px; }
  .footer { padding: 20px 30px; text-align: center; font-size: 12px; color: #64748b; }
  .footer a { color: #64748b; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>Weekly Progress Report</h1>
    <p>Hello {{ $user->name }} — here's how your training went this week.</p>
  </div>

  <div class="body">
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-value">{{ $stats['puzzle_rating'] }}</div>
        <div class="stat-label">Puzzle Rating</div>
      </div>
      <div class="stat-card">
        <div class="stat-value">{{ $stats['games_this_week'] }}</div>
        <div class="stat-label">Games This Week</div>
      </div>
      <div class="stat-card">
        <div class="stat-value">{{ $stats['avg_acc_this_week'] }}%</div>
        <div class="stat-label">Avg Accuracy</div>
        @if ($stats['avg_acc_delta'] != 0)
          <div class="stat-delta {{ $stats['avg_acc_delta'] > 0 ? 'delta-pos' : 'delta-neg' }}">
            {{ $stats['avg_acc_delta'] > 0 ? '+' : '' }}{{ $stats['avg_acc_delta'] }}% vs last week
          </div>
        @endif
      </div>
      <div class="stat-card">
        <div class="stat-value">{{ $stats['streak'] }}</div>
        <div class="stat-label">Day Streak</div>
      </div>
    </div>

    @if ($stats['weakest_theme'])
    <div class="section">
      <h2>Focus This Week</h2>
      <p>Your weakest tactical theme is <span class="pill theme">{{ $stats['weakest_theme'] }}</span>. Try drilling it in the puzzle trainer.</p>
    </div>
    @endif

    <div class="section">
      <h2>Recommended Activity</h2>
      <p>Keep up the momentum! This week, aim for:<br>
        &bull; 15 puzzles daily to improve pattern recognition<br>
        &bull; Review at least 2 of your games in depth<br>
        &bull; 1 opening trainer session to refresh your prep
      </p>
    </div>

    <a href="{{ config('app.frontend_url', 'http://localhost:8080') }}" class="cta">
      Open Chesiq →
    </a>
  </div>

  <div class="footer">
    <p>You're receiving this because you have an account on Chesiq.</p>
    <p><a href="{{ $unsubscribeUrl }}">Unsubscribe from weekly emails</a></p>
  </div>
</div>
</body>
</html>
