# Laravel Clone — Learning Framework Guide

> Build a minimal but functional PHP framework inspired by Laravel 13.
> Focus: **clarity, structure, reasoning** — not production completeness.

---

## 🗺 Architecture Map

```
HTTP Request
     │
     ▼
public/index.php          ← Entry point (Step 1)
     │
     ▼
bootstrap/app.php         ← Application boot (Step 2)
     │
     ▼
Application (Container)   ← IoC Container (Step 3)
     │
     ├── ServiceProviders  ← Registration (Step 4)
     │
     ▼
HttpKernel                ← Request handling (Step 5)
     │
     ▼
Pipeline (Middleware)     ← Request → Middleware chain (Step 6)
     │
     ▼
Router                    ← Route matching (Step 7)
     │
     ▼
Controller                ← Action dispatch (Step 8)
     │
     ▼
Response                  ← HTTP response (Step 9)
     │
     ▼
View / Blade              ← Template rendering (Step 10)
     │
     ▼
Config / Env              ← Configuration (Step 11)
     │
     ▼
Validation                ← Input validation (Step 12)
```

---

## 📚 Steps Index

| Step | Name | Key Concept | Laravel Equivalent |
|------|------|-------------|-------------------|
| [01](./01-entry-point.md) | Entry Point | Bootstrap flow | `public/index.php` |
| [02](./02-application-bootstrap.md) | Application Bootstrap | App creation | `bootstrap/app.php` |
| [03](./03-container.md) | IoC Container | Dependency injection | `Illuminate\Container\Container` |
| [04](./04-service-providers.md) | Service Providers | Service registration | `Illuminate\Support\ServiceProvider` |
| [05](./05-http-kernel.md) | HTTP Kernel | Request lifecycle | `Illuminate\Foundation\Http\Kernel` |
| [06](./06-middleware-pipeline.md) | Middleware Pipeline | Request filtering | `Illuminate\Pipeline\Pipeline` |
| [07](./07-router.md) | Router | Route matching | `Illuminate\Routing\Router` |
| [08](./08-request-response.md) | Request & Response | HTTP abstractions | `Illuminate\Http\Request` / `Response` |
| [09](./09-controller.md) | Controller | Action handling | `Illuminate\Routing\Controller` |
| [10](./10-view-engine.md) | View Engine | Template rendering | `Illuminate\View\View` |
| [11](./11-config-env.md) | Config & Env | Configuration | `Illuminate\Config\Repository` |
| [12](./12-validation.md) | Validation | Input validation | `Illuminate\Validation\Validator` |

---

## 🏗 Target Project Structure

```
laravel-clone/
├── app/
│   ├── Controllers/
│   │   └── HomeController.php
│   └── Providers/
│       └── AppServiceProvider.php
├── bootstrap/
│   └── app.php
├── config/
│   └── app.php
├── public/
│   └── index.php
├── resources/
│   └── views/
│       └── home.php
├── routes/
│   └── web.php
├── src/
│   ├── Container/
│   │   └── Container.php
│   ├── Foundation/
│   │   └── Application.php
│   ├── Http/
│   │   ├── Request.php
│   │   ├── Response.php
│   │   └── Kernel.php
│   ├── Pipeline/
│   │   └── Pipeline.php
│   ├── Routing/
│   │   ├── Router.php
│   │   └── Route.php
│   ├── Support/
│   │   └── ServiceProvider.php
│   ├── View/
│   │   └── View.php
│   ├── Config/
│   │   └── Repository.php
│   └── Validation/
│       └── Validator.php
└── composer.json
```

---

## 🧠 Core Design Principles Applied

### 1. Explicit Over Magic
Every component is wired **manually**. No hidden facades or static calls unless strictly necessary.

### 2. Constructor Injection
All dependencies are passed via `__construct()`. No service locator pattern.

### 3. Thin Classes
Each class does **one thing**. No god objects.

### 4. Match Laravel's Mental Model
Same names, same concepts — just simpler internals.

---

## ⚠️ What We Deliberately Skip

| Laravel Feature | Why We Skip |
|----------------|-------------|
| Eloquent ORM | Too complex; use PDO directly if needed |
| Events / Broadcasting | Not core to understanding the framework |
| Queue / Jobs | Background processing is out of scope |
| Artisan Console | CLI tools are secondary |
| Facades | Static proxies obscure what's happening |
| Cache | Focus on HTTP lifecycle first |
| Auth | Complex; not architectural core |
| Blade directives | Keep view engine minimal |

---

## 📐 Step Format Reminder

Each step document follows this exact format:

1. 🎯 **Purpose** — WHY this exists
2. 🧠 **Concept** — WHAT it is, mapped to Laravel
3. 🏗 **Implementation** — HOW (full code)
4. 🔗 **Integration** — How it connects to previous steps
5. ✅ **Usage Example** — Show it working
6. 📌 **Key Elements** — What was built
7. ⚠️ **Simplifications** — What was simplified vs Laravel

---

## 🚀 Getting Started

1. Read each step document in order
2. Build the code alongside the guide
3. Run the example at the end of each step
4. Move to the next step only when the current one works

**First step:** [01 — Entry Point →](./01-entry-point.md)
