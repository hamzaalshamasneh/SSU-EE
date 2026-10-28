$(document).ready(function () {
  // Request readings from data.php in the same folder.
  $.ajax({
    url: "data.php",
    method: "GET",
    dataType: "json",
    cache: false,

    success: function (data) {
      var x_axis = [];
      var y_axis = [];

      // Select node_1 and collect its timestamps and temperatures.
      for (var i = 0; i < data.length; i++) {
        if (data[i].node_name === "node_1") {
          x_axis.push(data[i].time_received);
          y_axis.push(Number(data[i].temperature));
        }
      }

      // Prepare the chart labels, colors, and values.
      var chartdata = {
        labels: x_axis,
        datasets: [
          {
            label: "Sensor Node node_1 — Temperature (°C)",
            backgroundColor: "rgba(200, 200, 200, 0.75)",
            borderColor: "rgba(200, 200, 200, 1)",
            borderWidth: 1,
            data: y_axis
          }
        ]
      };

      // Locate the canvas defined in gragh.html.
      var ctx = document.getElementById("mycanvas").getContext("2d");

      // Draw the temperature readings as a bar chart.
      new Chart(ctx, {
        type: "bar",
        data: chartdata
      });
    },

    error: function (xhr, status, error) {
      // Report a failed request.
      console.error("Unable to load sensor readings:", status, error);
      alert("Unable to load sensor readings. Check data.php.");
    }
  });
});
