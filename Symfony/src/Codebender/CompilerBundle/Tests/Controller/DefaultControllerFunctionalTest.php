<?php

namespace Codebender\CompilerBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DefaultControllerFunctionalTest extends WebTestCase
{
	public function testStatus()
	{
		$client = static::createClient();

		$client->request('GET', '/status');

		$this->assertEquals($client->getResponse()->getContent(), '{"success":true,"status":"OK"}');

	}

	public function testInvalidKey()
	{
		$client = static::createClient();

		$client->request('GET', '/inValidKey/v1');

		$this->assertEquals($client->getResponse()->getContent(), '{"success":false,"step":0,"message":"Invalid authorization key."}');

	}

	public function testInvalidAPI()
	{
		$client = static::createClient();

		$auth_key = $client->getContainer()->getParameter("auth_key");

		$client->request('GET', '/'.$auth_key.'/v666');

		$this->assertEquals($client->getResponse()->getContent(), '{"success":false,"step":0,"message":"Invalid API version."}');

	}

	public function testInvalidInput()
	{
		$client = static::createClient();

		$auth_key = $client->getContainer()->getParameter("auth_key");

		$client->request('GET', '/'.$auth_key.'/v1');

		$this->assertEquals($client->getResponse()->getContent(), '{"success":false,"step":0,"message":"Invalid input."}');

	}

	public function testBlinkUnoSyntaxCheck()
	{
		$files = array(array("filename" => "Blink.ino", "content" => "/*\n  Blink\n  Turns on an LED on for one second, then off for one second, repeatedly.\n \n  This example code is in the public domain.\n *///\n \n// Pin 13 has an LED connected on most Arduino boards.\n// give it a name:\nint led = 13;\n\n// the setup routine runs once when you press reset:\nvoid setup() {                \n  // initialize the digital pin as an output.\n  pinMode(led, OUTPUT);     \n}\n\n// the loop routine runs over and over again forever:\nvoid loop() {\n  digitalWrite(led, HIGH);   // turn the LED on (HIGH is the voltage level)\n  delay(1000);               // wait for a second\n  digitalWrite(led, LOW);    // turn the LED off by making the voltage LOW\n  delay(1000);               // wait for a second\n}\n"));
		$format = "syntax";
		$version = "105";
		$libraries = array();
		$build = array("mcu" => "atmega328p", "f_cpu" => "16000000", "core" => "arduino", "variant" => "standard");

		$data = json_encode(array("files" => $files, "format" => $format, "version" => $version, "libraries" => $libraries, "build" => $build));

		$client = static::createClient();

		$auth_key = $client->getContainer()->getParameter("auth_key");

		$client->request('POST', '/'.$auth_key.'/v1', array(),array(),array(),$data);

		$response = json_decode($client->getResponse()->getContent(), true);

		$this->assertEquals($response["success"], true);
		$this->assertTrue(is_numeric($response["time"]));

	}

	public function testBlinkUnoCompile()
	{
		$files = array(array("filename" => "Blink.ino", "content" => "/*\n  Blink\n  Turns on an LED on for one second, then off for one second, repeatedly.\n \n  This example code is in the public domain.\n *///\n \n// Pin 13 has an LED connected on most Arduino boards.\n// give it a name:\nint led = 13;\n\n// the setup routine runs once when you press reset:\nvoid setup() {                \n  // initialize the digital pin as an output.\n  pinMode(led, OUTPUT);     \n}\n\n// the loop routine runs over and over again forever:\nvoid loop() {\n  digitalWrite(led, HIGH);   // turn the LED on (HIGH is the voltage level)\n  delay(1000);               // wait for a second\n  digitalWrite(led, LOW);    // turn the LED off by making the voltage LOW\n  delay(1000);               // wait for a second\n}\n"));
		$format = "binary";
		$version = "105";
		$libraries = array();
		$build = array("mcu" => "atmega328p", "f_cpu" => "16000000", "core" => "arduino", "variant" => "standard");

		$data = json_encode(array("files" => $files, "format" => $format, "version" => $version, "libraries" => $libraries, "build" => $build));

		$client = static::createClient();

		$auth_key = $client->getContainer()->getParameter("auth_key");

		$client->request('POST', '/'.$auth_key.'/v1', array(), array(), array(), $data);

		$response = json_decode($client->getResponse()->getContent(), true);

		$this->assertEquals($response["success"], true);
		$this->assertTrue(is_numeric($response["time"]));
		$this->assertTrue(is_numeric($response["size"]));
	}

	public function testBlinkUnoSyntaxCheckError()
	{
		$files = array(array("filename" => "Blink.ino", "content" => "/*\n  Blink\n  Turns on an LED on for one second, then off for one second, repeatedly.\n \n  This example code is in the public domain.\n *///\n \n// Pin 13 has an LED connected on most Arduino boards.\n// give it a name:\nint led = 13\n\n// the setup routine runs once when you press reset:\nvoid setup() {                \n  // initialize the digital pin as an output.\n  pinMode(led, OUTPUT);\n  pinMode(led);     \n}\n\n// the loop routine runs over and over again forever:\nvoid loop() {\n  digitalWrite(led, HIGH);   // turn the LED on (HIGH is the voltage level)\n  delay(1000);               // wait for a second\n  digitalWrite(led, LOW);    // turn the LED off by making the voltage LOW\n  delay(1000);               // wait for a second\n}\n"));
		$format = "syntax";
		$version = "105";
		$libraries = array();
		$build = array("mcu" => "atmega328p", "f_cpu" => "16000000", "core" => "arduino", "variant" => "standard");

		$data = json_encode(array("files" => $files, "format" => $format, "version" => $version, "libraries" => $libraries, "build" => $build));

		$client = static::createClient();

		$auth_key = $client->getContainer()->getParameter("auth_key");

		$client->request('POST', '/'.$auth_key.'/v1', array(), array(), array(), $data);

		$response = json_decode($client->getResponse()->getContent(), true);

		$this->assertEquals($response["success"], false);
		$this->assertEquals($response["success"], false);
		$this->assertEquals($response["step"], 4);
		$this->assertContains("Blink.ino:10:13:", $response["message"]);
		$this->assertContains("expected ';' after top level declarator", $response["message"]);
		$this->assertContains("no matching function for call to 'pinMode'", $response["message"]);
		$this->assertContains("candidate function not viable: requires 2 arguments, but 1 was provided", $response["message"]);
		// $this->assertContains("2 errors generated.", $response["message"]); //unfortunately we no longer show how many errors were generated
	}

	public function testBlinkUnoCompileError()
	{
		$files = array(array("filename" => "Blink.ino", "content" => "/*\n  Blink\n  Turns on an LED on for one second, then off for one second, repeatedly.\n \n  This example code is in the public domain.\n *///\n \n// Pin 13 has an LED connected on most Arduino boards.\n// give it a name:\nint led = 13\n\n// the setup routine runs once when you press reset:\nvoid setup() {                \n  // initialize the digital pin as an output.\n  pinMode(led, OUTPUT);\n  pinMode(led);     \n}\n\n// the loop routine runs over and over again forever:\nvoid loop() {\n  digitalWrite(led, HIGH);   // turn the LED on (HIGH is the voltage level)\n  delay(1000);               // wait for a second\n  digitalWrite(led, LOW);    // turn the LED off by making the voltage LOW\n  delay(1000);               // wait for a second\n}\n"));
		$format = "binary";
		$version = "105";
		$libraries = array();
		$build = array("mcu" => "atmega328p", "f_cpu" => "16000000", "core" => "arduino", "variant" => "standard");

		$data = json_encode(array("files" => $files, "format" => $format, "version" => $version, "libraries" => $libraries, "build" => $build));

		$client = static::createClient();

		$auth_key = $client->getContainer()->getParameter("auth_key");

		$client->request('POST', '/'.$auth_key.'/v1', array(), array(), array(), $data);

		$response = json_decode($client->getResponse()->getContent(), true);

		$this->assertEquals($response["success"], false);
		$this->assertEquals($response["step"], 4);
		$this->assertContains("Blink.ino:10:13:", $response["message"]);
		$this->assertContains("expected ';' after top level declarator", $response["message"]);
		$this->assertContains("no matching function for call to 'pinMode'", $response["message"]);
		$this->assertContains("candidate function not viable: requires 2 arguments, but 1 was provided", $response["message"]);
		// $this->assertContains("2 errors generated.", $response["message"]);  //unfortunately we no longer show how many errors were generated
	}

	public function testIncorrectInputs()
	{
		$this->markTestIncomplete("No tests for invalid inputs yet");
	}
}
