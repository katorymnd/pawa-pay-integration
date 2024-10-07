<?php

namespace Katorymnd\PawaPayIntegration\Utils;

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Constraints as Assert;

class Validator
{
    // Validate that the input has only alphanumeric characters and spaces
    public static function validateAlphanumeric($input)
    {
        if (preg_match('/[^a-zA-Z0-9 ]/', $input)) {
            $suggestedInput = preg_replace('/[^a-zA-Z0-9 ]/', '', $input);
            throw new \Exception("The statement description contains invalid characters. Only alphanumeric characters and spaces are allowed. Suggested correction: '$suggestedInput'");
        }
        return $input;
    }

    // Validate that the length of the input does not exceed the specified max length
    public static function validateLength($input, $maxLength)
    {
        if (strlen($input) > $maxLength) {
            $suggestedInput = substr($input, 0, $maxLength);
            throw new \Exception("The statement description exceeds the allowed length of $maxLength characters. Suggested correction: '$suggestedInput'");
        }
        return $input;
    }

    // Full validation function: length + alphanumeric characters
    public static function validateStatementDescription($input, $maxLength = 22)
    {
        // Step 1: Ensure input has only alphanumeric characters and spaces
        self::validateAlphanumeric($input);

        // Step 2: Ensure length doesn't exceed the limit
        self::validateLength($input, $maxLength);

        return $input; // If validation passes, return the input
    }

    // Combined Symfony validation and regex validation for amount
    public static function symfonyValidateAmount($amount)
    {
        // Step 1: Validate using the provided regex (from pawaPay)
        $pattern = '/^([0]|([1-9][0-9]{0,17}))([.][0-9]{0,2})?$/';  // pawaPay's updated pattern
        if (!preg_match($pattern, $amount)) {
            throw new \Exception("The amount '$amount' is invalid. The amount must be a number with up to 18 digits before the decimal point and up to 2 decimal places.");
        }

        // Step 2: Use Symfony Validator to ensure it's positive and not blank
        $validator = Validation::createValidator();
        $violations = $validator->validate($amount, [
            new Assert\Positive(),  // Ensure amount is positive
            new Assert\NotBlank()   // Ensure amount is not blank
        ]);

        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                throw new \Exception($violation->getMessage());
            }
        }

        return $amount;
    }


    // General validator for Symfony
    public static function symfonyValidate($data, $constraints)
    {
        $validator = Validation::createValidator();
        $violations = $validator->validate($data, $constraints);

        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                throw new \Exception($violation->getMessage());
            }
        }

        return $data;
    }

    // Validate that the number of metadata items does not exceed 10
    public static function validateMetadataItemCount($metadata)
    {
        if (count($metadata) > 10) {
            throw new \Exception("Number of metadata items must not be more than 10. You provided " . count($metadata) . " items.");
        }
        return $metadata;
    }

    /**
     * Validate individual metadata fields: fieldName and fieldValue
     *
     * @param string $fieldName  The name of the metadata field
     * @param string $fieldValue The value of the metadata field
     *
     * @throws \Exception If validation fails
     * @return array An array containing the validated fieldName and fieldValue
     */
    public static function validateMetadataField($fieldName, $fieldValue)
    {
        // Define constraints for fieldName
        $fieldNameConstraints = [
            new Assert\NotBlank(['message' => 'Metadata field name cannot be blank.']),
            new Assert\Length([
                'max' => 50,
                'maxMessage' => 'Metadata field name cannot exceed {{ limit }} characters.'
            ]),
            new Assert\Regex([
                'pattern' => '/^[a-zA-Z0-9_ ]+$/',
                'message' => 'Metadata field name can only contain alphanumeric characters, underscores, and spaces.'
            ]),
        ];

        // Define constraints for fieldValue
        $fieldValueConstraints = [
            new Assert\NotBlank(['message' => 'Metadata field value cannot be blank.']),
            new Assert\Length([
                'max' => 100,
                'maxMessage' => 'Metadata field value cannot exceed {{ limit }} characters.'
            ]),
            new Assert\Regex([
                'pattern' => '/^[a-zA-Z0-9_\-., ]+$/',
                'message' => 'Metadata field value can only contain alphanumeric characters, underscores, hyphens, periods, commas, and spaces.'
            ]),
        ];

        // Validate fieldName
        self::symfonyValidate($fieldName, $fieldNameConstraints);

        // Validate fieldValue
        self::symfonyValidate($fieldValue, $fieldValueConstraints);

        return [
            'fieldName' => $fieldName,
            'fieldValue' => $fieldValue
        ];
    }
}