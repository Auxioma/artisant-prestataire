<?php

declare(strict_types=1);

namespace App\Content\Application\Menu;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * BO10.5 — éléments de menu : libellé, lien (chemin du site, https, route ou élément spécial), nouvel onglet,
 * visibilité par profil, actif, ordre. Les éléments spéciaux restent dans leur menu d'origine.
 */
final readonly class MenuHandler implements CommandHandler
{
    private const int LABEL_MAX = 60;
    private const int LINK_MAX = 500;
    private const string ROUTE = '/^route:[a-z0-9_]+(\?[a-z_]+=[a-z0-9-]+(&[a-z_]+=[a-z0-9-]+)*)?$/';

    public function __construct(private MenuStore $menus, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(MenuCommand $command): void
    {
        $now = $this->clock->now();
        $existing = $this->menus->find($command->id);
        match ($command->action) {
            'save' => $this->save($command, $existing, $now),
            'up', 'down' => null === $existing ? throw new MenuRejected('Élément introuvable.') : $this->menus->move($existing->id, 'up' === $command->action ? -1 : 1),
            'delete' => null === $existing ? throw new MenuRejected('Élément introuvable.') : $this->delete($existing),
            default => throw new MenuRejected('Action inconnue.'),
        };
        $this->audit->record(new AuditRecord(id: $this->ids->next(), occurredAt: $now, actorType: 'admin', actorId: $command->adminId, action: 'content.menu_'.$command->action, subjectType: 'menu_item', subjectId: $command->id));
    }

    private function save(MenuCommand $command, ?MenuItem $existing, \DateTimeImmutable $now): void
    {
        $menu = null !== $existing ? $existing->menu : (\is_string($command->data['menu'] ?? null) ? $command->data['menu'] : '');
        if (!\array_key_exists($menu, MenuItem::MENUS)) {
            throw new MenuRejected('Menu inconnu.');
        }
        $label = \is_string($command->data['label'] ?? null) ? trim($command->data['label']) : '';
        if ('' === $label || mb_strlen($label) > self::LABEL_MAX) {
            throw new MenuRejected(\sprintf('Libellé obligatoire (%d caractères au maximum).', self::LABEL_MAX));
        }
        $link = \is_string($command->data['link'] ?? null) ? trim($command->data['link']) : '';
        if (null !== $existing && str_starts_with($existing->link, 'special:')) {
            $link = $existing->link; // élément spécial : seul le libellé, la visibilité et l'état changent
        } elseif (!$this->validLink($link)) {
            throw new MenuRejected('Lien : saisissez un chemin du site (/…) ou une adresse https://.');
        }
        $audience = \is_string($command->data['audience'] ?? null) && \array_key_exists($command->data['audience'], MenuItem::AUDIENCES) ? $command->data['audience'] : 'all';
        $this->menus->save(new MenuItem(
            null !== $existing ? $existing->id : $command->id,
            $menu,
            $label,
            $link,
            null !== $existing ? $existing->icon : (str_starts_with($menu, 'account_') ? 'file' : null),
            true === ($command->data['new_tab'] ?? false),
            $audience,
            null !== $existing ? $existing->position : -1,
            null === $existing || true === ($command->data['active'] ?? false),
        ), $now);
    }

    private function delete(MenuItem $item): void
    {
        if (str_starts_with($item->link, 'special:')) {
            throw new MenuRejected('Élément spécial : désactivez-le plutôt que de le supprimer.');
        }
        $this->menus->delete($item->id);
    }

    private function validLink(string $link): bool
    {
        return mb_strlen($link) <= self::LINK_MAX && (
            (str_starts_with($link, '/') && !str_starts_with($link, '//') && 1 !== preg_match('/\s/', $link))
            || 1 === preg_match('#^https://[^\s/$.?\#][^\s]*$#iu', $link)
            || 1 === preg_match(self::ROUTE, $link)
        );
    }
}
