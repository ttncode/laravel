# Step 12: Validation

---

## 🚩 The Problem

When accepting user input (e.g., submitting a registration form), you must verify the data is correct and safe. Doing this manually inside a controller looks like this:

```php
public function store(Request $request)
{
    $errors = [];
    
    $email = $request->input('email');
    if (empty($email)) {
        $errors['email'] = 'Email is required';
    } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Email must be valid';
    }
    
    $password = $request->input('password');
    if (empty($password)) {
        $errors['password'] = 'Password is required';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters';
    }

    if (! empty($errors)) {
        return new Response(json_encode(['errors' => $errors]), 422);
    }
    
    // Save user...
}
```

**Why is this bad?**
1. **Repetitive:** You will write the exact same "required" and "email" checks in dozens of controllers.
2. **Messy:** The controller is dominated by validation logic instead of business logic.
3. **Inconsistent Error Messages:** It's easy to accidentally phrase error messages differently across different forms.

---

## 💡 The Solution: A Validator Component

We create a dedicated `Validator` class. It takes two arrays: the data to check, and the rules to apply. 

```php
$validator = new Validator($request->post, [
    'email'    => ['required', 'email'],
    'password' => ['required', 'min:8'],
]);

if ($validator->fails()) {
    return new Response(json_encode(['errors' => $validator->errors()]), 422);
}
```

The logic for *how* to validate an email or check string length is encapsulated inside the Validator class. The controller just declares *what* it expects.

---

## 🏗 Implementation

```bash
mkdir -p src/Validation
touch src/Validation/Validator.php
```

### File: `src/Validation/Validator.php`

```php
<?php

namespace Framework\Validation;

class Validator
{
    protected array $data;
    protected array $rules;
    protected array $errors = [];

    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    /**
     * Run all rules against the data.
     */
    public function validate(): void
    {
        foreach ($this->rules as $field => $fieldRules) {
            foreach ($fieldRules as $rule) {
                $this->applyRule($field, $rule);
            }
        }
    }

    /**
     * Parse and apply a single rule.
     */
    protected function applyRule(string $field, string $rule): void
    {
        // Parse rules like "min:8" into rule="min", param="8"
        $param = null;
        if (str_contains($rule, ':')) {
            list($rule, $param) = explode(':', $rule, 2);
        }

        $value = $this->data[$field] ?? null;

        switch ($rule) {
            case 'required':
                if (empty($value)) {
                    $this->addError($field, "The {$field} field is required.");
                }
                break;

            case 'email':
                if (! empty($value) && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "The {$field} must be a valid email address.");
                }
                break;

            case 'min':
                if (! empty($value) && strlen($value) < (int)$param) {
                    $this->addError($field, "The {$field} must be at least {$param} characters.");
                }
                break;
        }
    }

    protected function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function fails(): bool
    {
        $this->validate();
        return count($this->errors) > 0;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
```

### Test It in the Controller

Update `app/Controllers/HomeController.php` to handle a simulated form submission.

```php
<?php

namespace App\Controllers;

use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Validation\Validator;

class HomeController
{
    public function store(Request $request)
    {
        // Simulate some incoming POST data for the sake of the test
        $data = [
            'email' => 'invalid-email',
            'password' => '123' // too short
        ];

        $validator = new Validator($data, [
            'email'    => ['required', 'email'],
            'password' => ['required', 'min:8'],
            'name'     => ['required'], // missing entirely
        ]);

        if ($validator->fails()) {
            return new Response(json_encode($validator->errors()), 422, [
                'Content-Type' => 'application/json'
            ]);
        }

        return new Response("Validation passed!");
    }
}
```

Update `routes/web.php` to add the route:

```php
$router->get('/test-validation', [\App\Controllers\HomeController::class, 'store']);
```

---

## ✅ Verify

Run the server:
```bash
php -S 0.0.0.0:8000 -t public
```

Open `http://localhost:8000/test-validation`. You should see the JSON error response:

```json
{
  "email": [
    "The email must be a valid email address."
  ],
  "password": [
    "The password must be at least 8 characters."
  ],
  "name": [
    "The name field is required."
  ]
}
```

---

## 📌 What We Built

| Element | Purpose |
|---------|---------|
| `Validator` | Isolates rule parsing and data checking from the Controller. |
| Rules (`required`, `email`) | Reusable validation logic that ensures consistency. |

---

## ⚠️ Simplifications vs Laravel

| Laravel | Our Implementation | Reason |
|---------|-------------------|--------|
| Form Request classes | Inline Validator usage | FormRequests use Container auto-resolution and Form Validation Exceptions. |
| `ValidationException` | Manual `if ($validator->fails())` | Laravel throws an exception that the Exception Handler automatically converts to a 422 JSON response or a HTTP Redirect back to the form with old input. |
| Extensible Rule System | Hardcoded `switch` | Laravel uses an array/registry of Rule objects. |

---

## 🎉 Conclusion

You have successfully built a miniature PHP framework inspired by Laravel! 

You now understand how an IoC Container resolves dependencies, how a Front Controller funnels traffic through a Kernel, how Middleware forms a pipeline around your app, and how Requests are cleanly dispatched by a Router to Controllers.

These are the fundamental building blocks of almost every modern PHP framework. Understanding them makes using the real Laravel framework much less "magical".
