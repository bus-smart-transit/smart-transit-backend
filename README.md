<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

# SmartTransit Git Cheat Sheet

This guide outlines our standard Git workflow. Because the `main` branch is protected, **direct pushes to `main` are blocked**. All features, hotfixes, and updates must go through feature branches and Pull Requests (PRs).

---

## 1. Feature Branch Workflow

Always develop features on a dedicated branch instead of working directly on `main`.

- **Create and switch to a new feature branch:**
    ```bash
    git checkout -b feature/your-feature-name
    ```
    _Example: `git checkout -b feature/passenger-api`_
- **Switch back to an existing branch:**
    ```bash
    git checkout branch-name
    ```
- **List all local branches:**
    ```bash
    git branch
    ```

---

## 2. Saving Changes

Track and save your progress locally before sharing it with the team.

- **Check the status of your files (unstaged, staged, or modified):**
    ```bash
    git status
    ```
- **Stage specific files for a commit:**
    ```bash
    git add path/to/file.php
    ```
- **Stage ALL modified and new files at once:**
    ```bash
    git add .
    ```
- **Commit your staged changes with a clear description:**
    ```bash
    git commit -m "feat: implement passenger model mass assignment fix"
    ```

---

## 3. Pushing Code & Handling Protected Branches

Because direct pushes to `main` fail, use this workflow to get your code reviewed and merged.

1.  **Push your local feature branch to GitHub for the first time:**
    ```bash
    git push origin feature/your-feature-name
    ```
2.  **Open a Pull Request (PR):** Go to GitHub, click **"Compare & pull request"**, and assign a teammate to review it.
3.  **Approve a Peer's PR:** If a teammate asks you to review their code, go to their PR on GitHub, click **"Review changes"** (top right), select **"Approve"**, and submit.
4.  **Merge:** Once approved, hit the green **"Merge pull request"** button on GitHub!

---

## 4. Keeping Your Code Up-to-Date

Before starting new work, always pull the latest updates from the team.

- **Download the latest changes from GitHub without merging them:**
    ```bash
    git fetch --all
    ```
- **Pull and merge the latest `main` branch changes into your current branch:**
    ```bash
    git pull origin main
    ```

---

## 5. Cleaning Up Old Branches

Keep your workspace clean by removing branches that have already been merged or are no longer needed.

- **Delete a branch locally (safely):**
    ```bash
    git branch -d branch-name
    ```
- **Force-delete a local branch (even if unmerged):**
    ```bash
    git branch -D branch-name
    ```
- **Delete a branch on GitHub (the remote server):**
    ```bash
    git push origin --delete branch-name
    ```
    _(Note: If you get an error stating the remote ref does not exist, the branch is already gone from GitHub!)_
- **Prune your local tracking cache (removes dead remote branch references):**
    ```bash
    git fetch --prune
    ```

---

## 6. Emergency Undo Buttons

Did something break? Use these to safely step back.

- **Discard uncommitted changes in a specific file:**
    ```bash
    git checkout -- path/to/file.php
    ```
- **Discard ALL uncommitted changes in your current directory:**
    ```bash
    git reset --hard HEAD
    ```
- **Undo your last local commit while keeping your code intact in your text editor:**
    ```bash
    git reset --soft HEAD~1
    ```

---

> 💡 **Commit Message Tip:** Let's keep our history organized! Use prefixes like `feat:` for new features, `fix:` for bug fixes, `chore:` for configuration updates, and `refactor:` for code cleanups.
