# Modules

Empty on purpose in this milestone.

Each later feature area of the Academy arrives here as a self-contained
directory: the learner dashboard, the visual learning pathway, and the
application system. Keeping them apart means one can be worked on, disabled or
rewritten without touching the others.

## Adding a module

1. Create `modules/<slug>/class-kasa-<slug>-module.php`.
2. Extend `Kasa_Module` and implement `slug()`, `name()` and `boot()`.
3. Register it on the `kasa_academy_register_modules` action.

```php
add_action(
	'kasa_academy_register_modules',
	function ( $plugin ) {
		$plugin->register_module( new Kasa_Dashboard_Module() );
	}
);
```

Modules boot on `plugins_loaded` at priority 20, so LearnDash is loaded and it
is safe to call LearnDash functions from inside `boot()` and from the hooks it
registers.

## The one rule

A module must never work out for itself which learners a user is allowed to
see. It asks the scoping helper. The moment that logic is copied into a second
place, the two copies start to drift, and a scoping bug on a platform holding
data about children is a data leak rather than a display bug.

The role and capability layer is not a module. Everything else depends on it,
so it loads directly from `includes/`.
