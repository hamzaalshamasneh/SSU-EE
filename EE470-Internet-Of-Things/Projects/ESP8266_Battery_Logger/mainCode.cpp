/*
 * Project: Automatic Battery Voltage Logger
 * Course: EE470 - Introduction to the Internet of Things
 * Author: Hamza Alshamasneh
 * Date: September 30, 2026
 *
 * Purpose:
 * Measure battery voltage every 60 seconds.
 *
 *
 * Language: C++ | Framework: Arduino
 */

#include <Arduino.h>

const int batteryPin = A0;

// Time between readings: 60 seconds.
const unsigned long sampleInterval = 60000UL;

// External voltage divider resistors, in ohms.
const float R1 = 4700.0;
const float R2 = 1000.0;

// Nominal NodeMCU A0 values used in the conversion.
const float a0FullScale = 3.2;
const float a0InputResistance = 320000.0;

// Account for the onboard divider loading the lower resistor.
const float effectiveR2 =
    (R2 * a0InputResistance) / (R2 + a0InputResistance);

const float dividerFactor =
    (R1 + effectiveR2) / effectiveR2;

// Updated calibration using simultaneous measurements
const float calibrationFactor =
    (3.84 / 4.111) * (3.674 / 4.168);

const int sampleCount = 64;

unsigned long lastSampleTime = 0;
unsigned long readingNumber = 1;

// Average 64 ADC readings to reduce random fluctuations.
float measureBatteryVoltage()
{
    unsigned long adcTotal = 0;

    for (int i = 0; i < sampleCount; i++)
    {
        adcTotal += analogRead(batteryPin);
        delay(10); // Space the samples 10 milliseconds apart.
    }

    float averageADC = adcTotal / float(sampleCount);

    // Convert the average ADC value into voltage at A0.
    float a0Voltage =
        (averageADC / 1023.0) * a0FullScale;

    // Recover the voltage before the external divider.
    return a0Voltage * dividerFactor * calibrationFactor;
}

// Print the reading number and voltage to three decimal places.
void recordVoltage()
{
    float measuredVoltage = measureBatteryVoltage();

    Serial.print(readingNumber);
    Serial.print("] ");
    Serial.println(measuredVoltage, 3);

    readingNumber++;
}

void setup()
{
    Serial.begin(9600);
    delay(2000); // Allow the serial connection to settle.

    lastSampleTime = millis();
    recordVoltage(); // First reading near time zero.
}

void loop()
{
    unsigned long currentTime = millis();

    // Unsigned subtraction also handles millis() rollover.
    if (currentTime - lastSampleTime >= sampleInterval)
    {
        lastSampleTime = currentTime;
        recordVoltage();
    }

    delay(1); // Allow ESP8266 background tasks to run.
}
