<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1"
  >

  <title>EE470 | MQTT Dashboard</title>

  <style>
    :root {
      color-scheme: light;
      font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
      color: #18312e;
      background: #f1f6f5;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
    }

    main {
      max-width: 1180px;
      margin: auto;
      padding: 28px 20px 48px;
    }

    a {
      color: #126c65;
    }

    header {
      margin-bottom: 24px;
    }

    .eyebrow {
      color: #51736d;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 1px;
    }

    h1 {
      margin: 8px 0;
      font-size: clamp(28px, 5vw, 42px);
    }

    h2 {
      margin: 0 0 12px;
      font-size: 20px;
    }

    p {
      line-height: 1.6;
    }

    .muted {
      color: #58716d;
    }

    .cards {
      display: grid;
      grid-template-columns: 1fr 1fr 2fr;
      gap: 16px;
      margin-bottom: 18px;
    }

    .card,
    .panel {
      background: white;
      border: 1px solid #dce8e5;
      border-radius: 16px;
      padding: 22px;
    }

    .panel {
      margin-bottom: 18px;
    }

    .label {
      color: #58716d;
      font-size: 14px;
    }

    .metric {
      margin-top: 8px;
      font-size: 36px;
      font-weight: 750;
    }

    .timestamp {
      margin-top: 12px;
      font-size: 18px;
      font-weight: 650;
      overflow-wrap: anywhere;
    }

    .panel-head,
    .controls {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
    }

    .chart-wrap {
      position: relative;
      height: 350px;
      margin-top: 16px;
    }

    select,
    button {
      font: inherit;
      border-radius: 9px;
      padding: 10px 14px;
    }

    select {
      border: 1px solid #c9d9d5;
      background: white;
    }

    button {
      border: 0;
      color: white;
      background: #14796f;
      cursor: pointer;
      font-weight: 650;
    }

    button.secondary {
      background: #385c57;
    }

    button:disabled {
      opacity: 0.45;
      cursor: not-allowed;
    }

    button:focus-visible,
    select:focus-visible,
    a:focus-visible {
      outline: 3px solid #e3ad44;
      outline-offset: 3px;
    }

    .status {
      padding: 11px 14px;
      border-radius: 9px;
      background: #edf5f3;
      line-height: 1.5;
    }

    .led-state {
      margin: 12px 0;
      font-size: 20px;
      font-weight: 700;
    }

    .table-wrap {
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th,
    td {
      padding: 12px;
      text-align: left;
      border-bottom: 1px solid #e5eeeb;
      white-space: nowrap;
    }

    th {
      color: #58716d;
      font-size: 13px;
    }

    code {
      overflow-wrap: anywhere;
      color: #126c65;
    }

    footer {
      font-size: 13px;
      color: #58716d;
    }

    @media (max-width: 700px) {
      .cards {
        grid-template-columns: 1fr 1fr;
      }

      .cards .card:last-child {
        grid-column: 1 / -1;
      }

      .card,
      .panel {
        padding: 17px;
      }

      .chart-wrap {
        height: 290px;
      }
    }
  </style>

  <script
    src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"
    defer
  ></script>

  <script
    src="https://cdn.jsdelivr.net/npm/mqtt@5.10.4/dist/mqtt.min.js"
    defer
  ></script>
</head>

<body>
<main>
  <header>
    <a href="/IoT.html">← EE470 semester page</a>

    <p class="eyebrow">EE470 · INTRODUCTION TO MQTT</p>

    <h1>Potentiometer & LED Dashboard</h1>

    <p class="muted">
      Device: <strong>ee470-node-7k39</strong>.
      Potentiometer readings travel through HiveMQ and the
      Python relay before being saved in the database.
    </p>
  </header>

  <section class="cards" aria-label="Saved reading summary">
    <article class="card">
      <div class="label">Latest saved ADC value</div>
      <div id="latest" class="metric">—</div>
      <div class="label">ADC counts · 0–1023</div>
    </article>

    <article class="card">
      <div class="label">Saved potentiometer samples</div>
      <div id="count" class="metric">—</div>
      <div class="label">Total for this device</div>
    </article>

    <article class="card">
      <div class="label">Latest potentiometer database receipt</div>
      <div id="lastTime" class="timestamp">Waiting for data…</div>
    </article>
  </section>

  <section class="panel">
    <div class="panel-head">
      <h2>Saved potentiometer readings</h2>

      <label>
        Timezone
        <select id="zone">
          <option value="America/Los_Angeles">Pacific time</option>
          <option value="local">My device timezone</option>
          <option value="UTC">UTC</option>
        </select>
      </label>
    </div>

    <p id="dbStatus" class="status" role="status">
      Checking the database…
    </p>

    <div class="chart-wrap">
      <canvas
        id="potChart"
        role="img"
        aria-label="Saved potentiometer ADC values over time"
      ></canvas>
    </div>

    <p id="chartNote" class="muted">
      Latest 300 saved samples. Time is recorded when the
      database receives each insert.
    </p>

    <p id="refresh" class="muted">No database check completed yet.</p>
  </section>

  <section class="panel">
    <div class="panel-head">
      <h2>Live LED control</h2>

      <button id="connect" type="button">
        Connect MQTT
      </button>
    </div>

    <p class="muted">
      These controls communicate directly with HiveMQ.
      Saving reported LED changes requires the Python relay.
    </p>

    <p id="mqttStatus" class="status" role="status">
      MQTT is not connected.
    </p>

    <div class="label">ESP-reported LED state</div>

    <div id="ledState" class="led-state" aria-live="polite">
      Unknown
    </div>

    <div class="controls">
      <div>
        <button id="ledOn" type="button" disabled>LED ON · 1</button>
        <button
          id="ledOff"
          class="secondary"
          type="button"
          disabled
        >LED OFF · 0</button>
      </div>
    </div>

    <p id="commandStatus" class="muted" role="status">
      Command topic: <code>testtopic/temp/inTopic</code>
    </p>
  </section>

  <section class="panel">
    <h2>Recent saved events</h2>

    <p class="muted">
      pot = potentiometer ADC;
      switch = button pulse;
      led_state = LED state reported by the ESP.
    </p>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Database receipt time</th>
            <th>Reading type</th>
            <th>Value</th>
          </tr>
        </thead>

        <tbody id="rows">
          <tr>
            <td colspan="3">Loading saved events…</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <footer>
    Database checked every 5 seconds.
    The ESP normally publishes a potentiometer sample every 15 seconds.
    Stopping the Python relay stops new database inserts.
  </footer>
</main>

<script>
window.addEventListener('DOMContentLoaded', () => {
  const $ = id => document.getElementById(id);

  const BASE = 'testtopic/temp/outTopic/ee470-node-7k39';
  const COMMAND = 'testtopic/temp/inTopic';

  let cached = null;
  let liveClient = null;

  function dateOptions() {
    const value = $('zone').value;

    return value === 'local'
      ? {}
      : {timeZone: value};
  }

  function showTime(value) {
    return new Date(value).toLocaleString(
      undefined,
      dateOptions()
    );
  }

  const chart = window.Chart
    ? new Chart($('potChart'), {
        type: 'line',

        data: {
          datasets: [{
            label: 'Potentiometer ADC',
            data: [],
            borderColor: '#167b73',
            backgroundColor: '#167b731a',
            fill: true,
            pointRadius: 2,
            tension: 0
          }]
        },

        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: false,

          scales: {
            x: {
              type: 'linear',

              title: {
                display: true,
                text: 'Database receipt time'
              },

              ticks: {
                maxTicksLimit: 6,

                callback: value =>
                  new Date(value).toLocaleTimeString(
                    undefined,
                    dateOptions()
                  )
              }
            },

            y: {
              min: 0,
              max: 1023,

              title: {
                display: true,
                text: 'ADC counts'
              }
            }
          },

          plugins: {
            legend: {
              display: false
            },

            tooltip: {
              callbacks: {
                title: items =>
                  items.length
                    ? showTime(items[0].parsed.x)
                    : ''
              }
            }
          }
        }
      })
    : null;

  if (!chart) {
    $('chartNote').textContent =
      'Chart library unavailable. ' +
      'Saved readings remain in the table below.';
  }

  function render(data) {
    cached = data;

    const points = data.readings;

    const last = points.length
      ? points[points.length - 1]
      : null;

    $('latest').textContent = last
      ? last.reading_value
      : '—';

    $('count').textContent =
      data.pot_count.toLocaleString();

    $('lastTime').textContent = last
      ? showTime(last.received_at)
      : 'No saved reading yet';

    if (chart) {
      chart.data.datasets[0].data = points.map(row => ({
        x: Date.parse(row.received_at),
        y: row.reading_value
      }));

      chart.update();
    }

    $('rows').replaceChildren();

    for (const row of data.events) {
      const tr = document.createElement('tr');

      const values = [
        showTime(row.received_at),
        row.reading_type,
        row.reading_value
      ];

      for (const value of values) {
        const td = document.createElement('td');
        td.textContent = String(value);
        tr.appendChild(td);
      }

      $('rows').appendChild(tr);
    }

    if (!data.events.length) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');

      td.colSpan = 3;

      td.textContent =
        'No saved events yet. Start the Python relay.';

      tr.appendChild(td);
      $('rows').appendChild(tr);
    }

    const age = last
      ? Date.parse(data.server_time) -
        Date.parse(last.received_at)
      : Infinity;

    $('dbStatus').textContent = !last
      ? 'Waiting for the first saved reading'
      : age > 45000
        ? 'No recent saved reading — check the relay'
        : 'Recent saved readings available';

    $('refresh').textContent =
      'Last successful database check: ' +
      showTime(data.server_time);
  }

  async function refreshDatabase() {
    const controller = new AbortController();

    const timer = setTimeout(
      () => controller.abort(),
      10000
    );

    try {
      const response = await fetch(
        'mqtt_data.php',
        {
          cache: 'no-store',
          signal: controller.signal
        }
      );

      const data = await response.json();

      if (!response.ok || !data.ok) {
        throw new Error(
          data.error || 'Database request failed'
        );
      }

      render(data);

    } catch (error) {
      $('dbStatus').textContent =
        'Database check failed: ' + error.message;

    } finally {
      clearTimeout(timer);
      setTimeout(refreshDatabase, 5000);
    }
  }

  $('zone').addEventListener('change', () => {
    if (cached) render(cached);
  });

  function offline(text) {
    $('mqttStatus').textContent = text;

    $('ledOn').disabled = true;
    $('ledOff').disabled = true;

    $('ledState').textContent =
      'Unknown — no current MQTT connection';
  }

  $('connect').addEventListener('click', () => {
    if (!window.mqtt) {
      offline(
        'MQTT library unavailable; reload the page.'
      );
      return;
    }

    if (liveClient) return;

    $('connect').disabled = true;

    offline('Connecting to HiveMQ...');

    liveClient = mqtt.connect(
      'wss://broker.hivemq.com:8884/mqtt',
      {
        clientId:
          'ee470-web-' +
          crypto.randomUUID()
            .replaceAll('-', '')
            .slice(0, 10),

        clean: true,
        reconnectPeriod: 5000,
        connectTimeout: 15000,
        queueQoSZero: false
      }
    );

    liveClient.on('connect', () => {
      liveClient.subscribe(
        BASE + '/ledState',
        {qos: 0},

        (error, grants) => {
          if (
            error ||
            !grants ||
            grants.some(grant => grant.qos === 128)
          ) {
            offline('LED-state subscription failed.');
            return;
          }

          $('mqttStatus').textContent =
            'Connected to HiveMQ';

          $('ledOn').disabled = false;
          $('ledOff').disabled = false;
        }
      );
    });

    liveClient.on(
      'message',
      (topic, payload, packet) => {
        const value = payload.toString();

        if (
          topic === BASE + '/ledState' &&
          (value === '0' || value === '1')
        ) {
          $('ledState').textContent =
            (value === '1' ? 'ON' : 'OFF') +
            (
              packet.retain
                ? ' — last retained report'
                : ' — reported by the ESP'
            );
        }
      }
    );

    liveClient.on('close', () => {
      offline(
        'Disconnected — retrying automatically...'
      );
    });

    liveClient.on('reconnect', () => {
      offline('Reconnecting to HiveMQ...');
    });

    liveClient.on('error', error => {
      offline('MQTT error: ' + error.message);
    });
  });

  function sendLed(value) {
    if (!liveClient?.connected) return;

    liveClient.publish(
      COMMAND,
      value,
      {
        qos: 0,
        retain: false
      },

      error => {
        $('commandStatus').textContent = error
          ? 'Publish failed: ' + error.message
          : 'Command ' + value +
            ' sent. Check the ESP’s reported state ' +
            'and physical LED.';
      }
    );
  }

  $('ledOn').addEventListener(
    'click',
    () => sendLed('1')
  );

  $('ledOff').addEventListener(
    'click',
    () => sendLed('0')
  );

  refreshDatabase();
});
</script>
</body>
</html>
