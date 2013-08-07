<?php

namespace Codebender\CompilerBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DefaultControllerTest extends WebTestCase
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
		$headers = array();
		$libraries = array();
		$build = array("mcu" => "atmega328p", "f_cpu" => "16000000", "core" => "arduino", "variant" => "standard");

		$data = json_encode(array("files" => $files, "format" => $format, "version" => $version, "headers" => $headers, "libraries" => $libraries, "build" => $build));

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
		$headers = array();
		$libraries = array();
		$build = array("mcu" => "atmega328p", "f_cpu" => "16000000", "core" => "arduino", "variant" => "standard");

		$data = json_encode(array("files" => $files, "format" => $format, "version" => $version, "headers" => $headers, "libraries" => $libraries, "build" => $build));

		$client = static::createClient();

		$auth_key = $client->getContainer()->getParameter("auth_key");

		$client->request('POST', '/'.$auth_key.'/v1', array(), array(), array(), $data);

		$response = json_decode($client->getResponse()->getContent(), true);

		$this->assertEquals($response["success"], true);
		$this->assertTrue(is_numeric($response["time"]));
		$this->assertTrue(is_numeric($response["size"]));
		//TODO: Find a way to make this work with both linux and os x
//		$this->assertEquals($response["output"], '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');
	}

	public function testBlinkUnoSyntaxCheckError()
	{
		$files = array(array("filename" => "Blink.ino", "content" => "/*\n  Blink\n  Turns on an LED on for one second, then off for one second, repeatedly.\n \n  This example code is in the public domain.\n *///\n \n// Pin 13 has an LED connected on most Arduino boards.\n// give it a name:\nint led = 13\n\n// the setup routine runs once when you press reset:\nvoid setup() {                \n  // initialize the digital pin as an output.\n  pinMode(led, OUTPUT);\n  pinMode(led);     \n}\n\n// the loop routine runs over and over again forever:\nvoid loop() {\n  digitalWrite(led, HIGH);   // turn the LED on (HIGH is the voltage level)\n  delay(1000);               // wait for a second\n  digitalWrite(led, LOW);    // turn the LED off by making the voltage LOW\n  delay(1000);               // wait for a second\n}\n"));
		$format = "syntax";
		$version = "105";
		$headers = array();
		$libraries = array();
		$build = array("mcu" => "atmega328p", "f_cpu" => "16000000", "core" => "arduino", "variant" => "standard");

		$data = json_encode(array("files" => $files, "format" => $format, "version" => $version, "headers" => $headers, "libraries" => $libraries, "build" => $build));

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
		$this->assertContains("2 errors generated.", $response["message"]);
	}

	public function testBlinkUnoCompileError()
	{
		$files = array(array("filename" => "Blink.ino", "content" => "/*\n  Blink\n  Turns on an LED on for one second, then off for one second, repeatedly.\n \n  This example code is in the public domain.\n *///\n \n// Pin 13 has an LED connected on most Arduino boards.\n// give it a name:\nint led = 13\n\n// the setup routine runs once when you press reset:\nvoid setup() {                \n  // initialize the digital pin as an output.\n  pinMode(led, OUTPUT);\n  pinMode(led);     \n}\n\n// the loop routine runs over and over again forever:\nvoid loop() {\n  digitalWrite(led, HIGH);   // turn the LED on (HIGH is the voltage level)\n  delay(1000);               // wait for a second\n  digitalWrite(led, LOW);    // turn the LED off by making the voltage LOW\n  delay(1000);               // wait for a second\n}\n"));
		$format = "binary";
		$version = "105";
		$headers = array();
		$libraries = array();
		$build = array("mcu" => "atmega328p", "f_cpu" => "16000000", "core" => "arduino", "variant" => "standard");

		$data = json_encode(array("files" => $files, "format" => $format, "version" => $version, "headers" => $headers, "libraries" => $libraries, "build" => $build));

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
		$this->assertContains("2 errors generated.", $response["message"]);
	}

	public function testIncorrectInputs()
	{
		$this->markTestIncomplete("No tests for invalid inputs yet");
	}
}
