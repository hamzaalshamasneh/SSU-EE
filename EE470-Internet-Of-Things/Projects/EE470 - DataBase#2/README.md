# EE470 Database Assignment Part 2

**Student:** Hamza Alshamasneh  
**Course:** EE470 Introduction to the Internet of Things  
**Institution:** Sonoma State University

This project extends the sensor database created in Part 1. PHP connects
to the MariaDB database on Hostinger to display sensor information,
receive readings, calculate statistics, and provide JSON output.
Chart.js displays the selected sensor's temperature readings.

## Live pages

- [Main course webpage](https://hamzaalshamasneh.com/IoT.html)
- [Sensor dashboard](https://hamzaalshamasneh.com/Chartjs/display.php)
- [JSON readings for node_3](https://hamzaalshamasneh.com/Chartjs/data.php?node=node_3)
- [Earlier standalone graph](https://hamzaalshamasneh.com/Chartjs/gragh.html)

The PHP application runs on Hostinger. This GitHub folder contains its
source code.

## Files

| File | Purpose |
|---|---|
| `Chartjs/display.php` | Displays registered sensors and received readings, provides a node-selection dropdown, calculates statistics, and draws the green line graph. |
| `Chartjs/insert.php` | Accepts GET, POST, and PUT requests, decodes Base64 messages, validates readings, and inserts accepted data. |
| `Chartjs/data.php` | Retrieves one selected node's readings and returns them as JSON. |
| `Chartjs/app.js` | JavaScript for the earlier standalone Chart.js example. |
| `Chartjs/gragh.html` | Webpage for the earlier standalone graph. |

The final dashboard includes its chart JavaScript directly in
`display.php`.

## Database

The application uses the database created in Part 1:

- `sensor_register`: node name, manufacturer, longitude, and latitude.
- `sensor_data`: node name, time received, temperature, and humidity.
- `sensor_combined`: a view joining registration information and readings.

The registered sensor nodes are `node_1` through `node_5`.

## Dashboard

The dashboard displays:

- All registered sensors.
- All received readings, sorted by node name and then timestamp.
- A dropdown populated from the registered nodes.
- A temperature-versus-time graph for the selected node.
- Average temperature.
- Average humidity.
- Maximum temperature.
- Minimum humidity.

The graph and statistics update when a different node is selected.
Refreshing the page retrieves the current database readings.

## Inserting readings

The insertion endpoint is:

```text
https://hamzaalshamasneh.com/Chartjs/insert.php
```

Accepted parameter names:

| Parameter | Purpose |
|---|---|
| `nodeId` | Registered node name. |
| `nodeTemp` | Temperature in degrees Celsius. |
| `nodeHumidity` | Relative humidity percentage. |
| `timeReceived` | Timestamp in `YYYY-MM-DD HH:MM:SS` format. |

Example GET request:

```text
https://hamzaalshamasneh.com/Chartjs/insert.php?nodeId=node_1&timeReceived=2022-10-01%2019:00:00&nodeTemp=28&nodeHumidity=57
```

Use an unused timestamp when testing a new reading.

GET, POST, and PUT requests can supply parameters in the URL. POST and
PUT also support URL-encoded form bodies.

## Validation

The application:

- Accepts multiple readings for each registered node.
- Rejects readings from unregistered nodes.
- Rejects duplicate timestamps for the same node.
- Accepts temperatures from -10 to 100 degrees Celsius.
- Accepts humidity values from 0 to 100 percent.
- Rejects invalid supplied timestamps.
- Uses the database's current timestamp when an ordinary request omits
  `timeReceived`.

## Base64 messages

The endpoint accepts Base64 messages using either format:

```text
insert.php?data=BASE64_MESSAGE
insert.php?BASE64_MESSAGE
```

The decoded message contains the same parameter names as an ordinary
request. Base64 messages must include `timeReceived`.

The PHP program uses `base64_decode()` to decode the message and
`parse_str()` to extract its parameters. The decoded values go through
the same validation and insertion process.

Base64 is encoding, not encryption.

## JSON output

Use the `node` parameter to select a sensor:

```text
https://hamzaalshamasneh.com/Chartjs/data.php?node=node_3
```

The response contains that node's database readings in timestamp order,
including node name, timestamp, temperature, and humidity.

## Setup

1. Use the sensor database and tables created in Part 1.
2. Enter the database connection settings in the three PHP files.
3. Replace `YOUR_DATABASE_PASSWORD` with the database user's password
   in the deployed copies.
4. Upload the `Chartjs` folder to Hostinger's `public_html` directory.
5. Open `Chartjs/display.php` through the website.
6. Test insertion using a browser, curl, or Postman.

Database passwords are omitted from the GitHub copies.

## References

The project was developed using the course lecture slides and examples,
adapted to my own database and sensor data.

- [Course Chart.js examples](https://github.com/faridfarahmand/SSU/tree/main/IoTCourse/Chartjs)
- [Chart.js documentation](https://www.chartjs.org/docs/latest/)
- [PHP documentation](https://www.php.net/manual/en/)
