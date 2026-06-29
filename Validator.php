<?php
class Validator {
    private const RULES = [
        'full_name' => [
            'required' => true,
            'pattern' => '/^[a-zA-Zа-яА-ЯёЁ\s\-]{1,150}$/u',
            'message' => 'ФИО должно содержать только буквы, пробелы и дефисы (не более 150 символов)'
        ],
        'phone' => [
            'required' => true,
            'pattern' => '/^[\d\s\+\(\)-]{5,20}$/',
            'message' => 'Телефон должен содержать только цифры, пробелы, +, (, ), - (5-20 символов)'
        ],
        'email' => [
            'required' => true,
            'filter' => FILTER_VALIDATE_EMAIL,
            'message' => 'Введите корректный email'
        ],
        'birth_date' => [
            'required' => true,
            'custom' => 'validateBirthDate',
            'message' => 'Дата рождения не может быть в будущем'
        ],
        'gender' => [
            'required' => true,
            'values' => ['male', 'female'],
            'message' => 'Выберите пол'
        ],
        'pets' => [
            'required' => true,
            'custom' => 'validatePets',
            'message' => 'Выберите хотя бы одно животное'
        ],
        'agreed' => [
            'required' => true,
            'values' => ['1', 1, true],
            'message' => 'Вы должны согласиться с условиями опекунства'
        ]
    ];
    
    private const ALLOWED_PETS = [
        'Кошка',
        'Собака',
        'Хомяк',
        'Попугай',
        'Кролик',
        'Черепаха',
        'Шиншилла',
        'Морская свинка',
        'Хорек',
        'Игуана',
        'Ёжик',
        'Рыбки'
    ];
    
    public static function validate(array $data): array {
        $errors = [];
        
        foreach (self::RULES as $field => $rules) {
            $value = $data[$field] ?? null;
            
            if (!empty($rules['required']) && empty($value) && $value !== '0') {
                $errors[$field] = $rules['message'];
                continue;
            }
            
            if (empty($value) && $value !== '0') continue;
            
            if (isset($rules['pattern']) && !preg_match($rules['pattern'], $value)) {
                $errors[$field] = $rules['message'];
                continue;
            }
            
            if (isset($rules['filter']) && !filter_var($value, $rules['filter'])) {
                $errors[$field] = $rules['message'];
                continue;
            }
            
            if (isset($rules['values']) && !in_array($value, $rules['values'], true)) {
                $errors[$field] = $rules['message'];
                continue;
            }
            
            if (isset($rules['custom']) && method_exists(self::class, $rules['custom'])) {
                if (!self::{$rules['custom']}($value)) {
                    $errors[$field] = $rules['message'];
                }
            }
        }
        
        return $errors;
    }
    
    private static function validateBirthDate(string $value): bool {
        $timestamp = strtotime($value);
        return $timestamp !== false && $timestamp <= time();
    }
    
    private static function validatePets(mixed $value): bool {
        if (!is_array($value) || empty($value)) return false;
        foreach ($value as $pet) {
            if (!in_array($pet, self::ALLOWED_PETS, true)) return false;
        }
        return true;
    }
    
    public static function getAllowedPets(): array {
        return self::ALLOWED_PETS;
    }
}