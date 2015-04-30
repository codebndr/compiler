<?php

namespace Codebender\CompilerBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DefaultControllerFunctionalTest extends WebTestCase
{
	public function testStatus() {
		$client = static::createClient();

		$client->request('GET', '/status');

		$this->assertEquals($client->getResponse()->getContent(), '{"success":true,"status":"OK"}');

	}

	public function testInvalidKey() {
		$client = static::createClient();

		$client->request('GET', '/inValidKey/v1');

		$this->assertEquals($client->getResponse()->getContent(), '{"success":false,"step":0,"message":"Invalid authorization key."}');

	}

	public function testInvalidAPI() {
		$client = static::createClient();

		$auth_key = $client->getContainer()->getParameter("auth_key");

		$client->request('GET', '/'.$auth_key.'/v666');

		$this->assertEquals($client->getResponse()->getContent(), '{"success":false,"step":0,"message":"Invalid API version."}');

	}

	public function testInvalidInput() {
		$client = static::createClient();

		$auth_key = $client->getContainer()->getParameter("auth_key");

		$client->request('GET', '/'.$auth_key.'/v1');

		$this->assertEquals($client->getResponse()->getContent(), '{"success":false,"step":0,"message":"Invalid input."}');

	}

	public function testBlinkUnoSyntaxCheck() {
		$files = array(array("filename" => "Blink.ino", "content" => "int led = 13;\nvoid setup() {pinMode(led, OUTPUT);}\nvoid loop() {\ndigitalWrite(led, HIGH);\ndelay(1000);\ndigitalWrite(led, LOW);\ndelay(1000);\n}\n"));
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

	public function testBlinkUnoCompile() {
		$files = array(array("filename" => "Blink.ino", "content" => "\nint led = 13;\nvoid setup() {\npinMode(led, OUTPUT);\n}\nvoid loop() {\ndigitalWrite(led, HIGH);\ndelay(1000);\ndigitalWrite(led, LOW);\ndelay(1000);\n}\n"));
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

        $objectFilesPath = $client->getContainer()->getParameter('temp_dir') . '/' . $client->getContainer()->getParameter('objdir');
        $coreObjectLibrary = glob("$objectFilesPath/*__v105__hardware__arduino__cores__arduino________atmega328p_16000000_arduino_standard_null_null_______core.a");
        $this->assertTrue(count($coreObjectLibrary) > 0);
	}

	public function testBlinkUnoSyntaxCheckError() {
		$files = array(array("filename" => "Blink.ino", "content" => "\nint led = 13\nvoid setup() {\npinMode(led, OUTPUT);\npinMode(led);\n}\nvoid loop() {\ndigitalWrite(led, HIGH);\ndelay(1000);\ndigitalWrite(led, LOW);\ndelay(1000);\n}\n"));
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
		$this->assertContains("Blink.ino:2:13:", $response["message"]);
		$this->assertContains("expected ';' after top level declarator", $response["message"]);
		$this->assertContains("no matching function for call to 'pinMode'", $response["message"]);
		$this->assertContains("candidate function not viable: requires 2 arguments, but 1 was provided", $response["message"]);
		// $this->assertContains("2 errors generated.", $response["message"]); //unfortunately we no longer show how many errors were generated
	}

	public function testBlinkUnoCompileError() {
		$files = array(array("filename" => "Blink.ino", "content" => "\nint led = 13\nvoid setup() {\npinMode(led, OUTPUT);\npinMode(led);\n}\nvoid loop() {\ndigitalWrite(led, HIGH);\ndelay(1000);\ndigitalWrite(led, LOW);\n  delay(1000);\n}\n"));
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
		$this->assertContains("Blink.ino:2:13:", $response["message"]);
		$this->assertContains("expected ';' after top level declarator", $response["message"]);
		$this->assertContains("no matching function for call to 'pinMode'", $response["message"]);
		$this->assertContains("candidate function not viable: requires 2 arguments, but 1 was provided", $response["message"]);
		// $this->assertContains("2 errors generated.", $response["message"]);  //unfortunately we no longer show how many errors were generated
	}

    public function testExternalVariant() {
        $files = array(array('filename' => 'Blink.ino', 'content' => "void setup(){}\nvoid loop(){}\n"));
        $format = 'binary';
        $version = '105';
        $libraries = array();
        $build = array('mcu' => 'atmega32u4', 'f_cpu' => '8000000', 'core' => 'arduino', 'variant' => 'flora', 'pid' => '0x8004', 'vid' => '0x239A');
        $data = json_encode(array("files" => $files, "format" => $format, "version" => $version, "libraries" => $libraries, "build" => $build));

        $client = static::createClient();

        $auth_key = $client->getContainer()->getParameter("auth_key");

        $client->request('POST', '/'.$auth_key.'/v1', array(), array(), array(), $data);

        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertEquals($response['success'], true);
        $objectFilesPath = $client->getContainer()->getParameter('temp_dir') . '/' . $client->getContainer()->getParameter('objdir');
        $coreObjectLibrary = glob("$objectFilesPath/*v105__hardware__arduino__cores__arduino________atmega32u4_8000000_arduino_flora_0x239A_0x8004_______core.a");

        $this->assertTrue(count($coreObjectLibrary) > 0);
    }

    public function testExternalCore() {
        $files = array(array('filename' => 'Blink.ino', 'content' => "void setup(){}\nvoid loop(){}\n"));
        $format = 'binary';
        $version = '105';
        $libraries = array();
        $build = array('mcu' => 'attiny85', 'f_cpu' => '8000000', 'core' => 'tiny');
        $data = json_encode(array("files" => $files, "format" => $format, "version" => $version, "libraries" => $libraries, "build" => $build));

        $client = static::createClient();

        $auth_key = $client->getContainer()->getParameter("auth_key");

        $client->request('POST', '/'.$auth_key.'/v1', array(), array(), array(), $data);

        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertEquals($response['success'], true);
        $objectFilesPath = $client->getContainer()->getParameter('temp_dir') . '/' . $client->getContainer()->getParameter('objdir');
        $coreObjectLibrary = glob("$objectFilesPath/*__external_cores__tiny__cores__tiny________attiny85_8000000_tiny__null_null_______core.a");

        $this->assertTrue(count($coreObjectLibrary) > 0);
    }

    public function testAutocomplete() {
        $this->markTestIncomplete('No tests for the code completion feature yet.');
    }

	public function testIncorrectInputs() {
		$this->markTestIncomplete("No tests for invalid inputs yet");
	}
}
