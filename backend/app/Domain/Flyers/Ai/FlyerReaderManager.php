<?php

namespace App\Domain\Flyers\Ai;

use Anthropic\Client;
use App\Domain\Flyers\FlyerSettings;
use InvalidArgumentException;

/** The flyer reader admins chose (FlyerSettings provider and model), and the fallback when one is set. */
class FlyerReaderManager
{
    private ?FlyerReader $fake = null;

    /** Tests: every page is read by this reader instead of an AI. */
    public static function fake(FlyerReader $reader): FlyerReader
    {
        $manager = new self;
        $manager->fake = $reader;
        app()->instance(self::class, $manager);

        return $reader;
    }

    public function main(): FlyerReader
    {
        $settings = FlyerSettings::all();

        return $this->make($settings['provider'], $settings['model'], $settings['effort']);
    }

    /** The second choice, used when the main reader fails a page; null when admins set none. */
    public function fallback(): ?FlyerReader
    {
        $settings = FlyerSettings::all();
        if ($this->fake || blank($settings['fallback_provider']) || blank($settings['fallback_model'])) {
            return null;
        }

        return $this->make($settings['fallback_provider'], $settings['fallback_model'], $settings['effort']);
    }

    public function make(string $provider, string $model, string $effort = 'low'): FlyerReader
    {
        if ($this->fake) {
            return $this->fake;
        }

        return match ($provider) {
            'claude' => new ClaudeFlyerReader(new Client(apiKey: (string) config('services.anthropic.key')), $model, $effort),
            'openai' => new OpenAiFlyerReader((string) config('services.openai.key'), $model, $effort, (string) config('services.openai.url')),
            default => throw new InvalidArgumentException("Unknown flyer AI provider [{$provider}]."),
        };
    }
}
