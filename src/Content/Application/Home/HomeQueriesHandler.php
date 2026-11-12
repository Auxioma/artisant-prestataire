<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

use App\Content\Application\Port\HomeVersions;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

/**
 * @phpstan-import-type Block from HomeVersion
 */
final readonly class HomeQueriesHandler implements QueryHandler
{
    private const int HISTORY = 30;

    public function __construct(private HomeVersions $versions, private HomeDefaults $defaults, private Clock $clock)
    {
    }

    /** @return HomeEditorView|list<Block> */
    public function __invoke(HomeEditorState|PublishedHome $query): HomeEditorView|array
    {
        $now = $this->clock->now();
        if ($query instanceof PublishedHome) {
            $version = null === $query->previewVersionId ? $this->versions->live($now) : $this->versions->find($query->previewVersionId);
            $blocks = $version->blocks ?? $this->defaults->blocks();

            return array_values(array_filter($blocks, static fn (array $b): bool => $b['enabled']));
        }
        $draft = $this->versions->draft();
        $live = $this->versions->live($now);
        $scheduled = $this->versions->scheduled($now);

        return new HomeEditorView(
            ($draft ?? $scheduled ?? $live)->blocks ?? $this->defaults->blocks(),
            $draft,
            $live,
            $scheduled,
            $this->versions->history(self::HISTORY),
        );
    }
}
