<?php

namespace App\Providers;

use App\Domain\Access\AccessRules;
use App\Domain\Brochures\Brochure;
use App\Domain\Catalog\Brand;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductGroup;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Offers\Offer;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Domain\Retail\RetailGroup;
use App\Domain\Search\Synonym;
use App\Http\Responses\PasskeySignedIn;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;
use Laravel\Passkeys\Passkeys;
use Spatie\Permission\Models\Role;
use Typesense\Client as TypesenseClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TypesenseClient::class, fn () => new TypesenseClient(config('scout.typesense.client-settings')));

        // Passkey routes live in routes/api.php (/api/v1/…), and sign-in answers like the email-code sign-in.
        Passkeys::ignoreRoutes();
        $this->app->singleton(PasskeyLoginResponse::class, PasskeySignedIn::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin permissions: one policy per section (App\Domain\Access\AccessRules), e.g. Branch → BranchPolicy.
        foreach (AccessRules::sections() as $section) {
            Gate::policy($section['model'], 'App\\Policies\\'.class_basename($section['model']).'Policy');
        }

        // Likes, reports and the activity log store these short names instead of PHP class names.
        Relation::enforceMorphMap([
            'product' => Product::class,
            'variant' => ProductVariant::class,
            'offer' => Offer::class,
            'branch' => Branch::class,
            'user' => User::class,
            'retail_group' => RetailGroup::class,
            'chain' => Chain::class,
            'brochure' => Brochure::class,
            'category' => Category::class,
            'brand' => Brand::class,
            'product_group' => ProductGroup::class,
            'synonym' => Synonym::class,
            'role' => Role::class,
        ]);

        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));
        RateLimiter::for('login-code', fn (Request $request) => [
            Limit::perMinute(3)->by('email:'.mb_strtolower((string) $request->input('email'))),
            Limit::perHour(20)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('login-verify', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('reports', fn (Request $request) => Limit::perHour(30)->by($request->user('sanctum')?->id ?: $request->ip()));
        RateLimiter::for('account', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Catch lazy loading, silently discarded attributes and missing attributes while developing.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
