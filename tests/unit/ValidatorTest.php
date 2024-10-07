<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Katorymnd\PawaPayIntegration\Utils\Validator;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Exception\ValidationFailedException;

class ValidatorTest extends TestCase
{
    /**
     * Test alphanumeric validation
     */
    public function testValidateAlphanumericValid()
    {
        $input = "ValidInput123";
        $result = Validator::validateAlphanumeric($input);
        $this->assertEquals($input, $result);
    }

    public function testValidateAlphanumericInvalid()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("The statement description contains invalid characters.");
        Validator::validateAlphanumeric("Invalid@Input#");
    }

    /**
     * Test length validation
     */
    public function testValidateLengthValid()
    {
        $input = "ShortText";
        $result = Validator::validateLength($input, 20);
        $this->assertEquals($input, $result);
    }

    public function testValidateLengthInvalid()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("The statement description exceeds the allowed length");
        Validator::validateLength("This text is too long", 10);
    }

    /**
     * Test full statement description validation (alphanumeric + length)
     */
    public function testValidateStatementDescriptionValid()
    {
        $input = "ValidStatement";
        $result = Validator::validateStatementDescription($input, 20);
        $this->assertEquals($input, $result);
    }

    public function testValidateStatementDescriptionInvalid()
    {
        $this->expectException(\Exception::class);
        Validator::validateStatementDescription("Invalid@Description", 20);
    }

    /**
     * Test Symfony validation for valid amount based on pawaPay's regex
     */
    public function testSymfonyValidateAmountValid()
    {
        // Test valid amounts
        $this->assertEquals('5', Validator::symfonyValidateAmount('5'));
        $this->assertEquals('5.00', Validator::symfonyValidateAmount('5.00'));
        $this->assertEquals('0.5', Validator::symfonyValidateAmount('0.5'));
        $this->assertEquals('5555555', Validator::symfonyValidateAmount('5555555'));
    }

    public function testSymfonyValidateAmountInvalid()
    {
        // Test invalid amounts
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("The amount '5.555' is invalid.");
        Validator::symfonyValidateAmount('5.555');  // Too many decimal places

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("The amount '5555555555555555555' is invalid.");
        Validator::symfonyValidateAmount('5555555555555555555');  // Exceeds digit limit

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("The amount '.5' is invalid.");
        Validator::symfonyValidateAmount('.5');  // No leading zero

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("The amount '00.5' is invalid.");
        Validator::symfonyValidateAmount('00.5');  // Multiple leading zeros
    }

    /**
     * Test metadata validation for item count
     */
    public function testValidateMetadataItemCountValid()
    {
        $metadata = ['key1' => 'value1', 'key2' => 'value2'];
        $result = Validator::validateMetadataItemCount($metadata);
        $this->assertEquals($metadata, $result);
    }

    public function testValidateMetadataItemCountInvalid()
    {
        $this->expectException(\Exception::class);
        $metadata = array_fill(0, 11, 'metadata');  // Creates 11 items
        Validator::validateMetadataItemCount($metadata);
    }

    /**
     * Test metadata field validation (fieldName and fieldValue)
     */
    public function testValidateMetadataFieldValid()
    {
        $result = Validator::validateMetadataField('fieldName_1', 'value-123');
        $this->assertEquals('fieldName_1', $result['fieldName']);
        $this->assertEquals('value-123', $result['fieldValue']);
    }

    public function testValidateMetadataFieldInvalid()
    {
        $this->expectException(\Exception::class);
        Validator::validateMetadataField('Invalid@FieldName', 'value#123');
    }
}