<?php

namespace App\Filament\Resources;

use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use BezhanSalleh\FilamentShield\Forms\ShieldSelectAllToggle;
use App\Filament\Resources\RoleResource\Pages;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use BezhanSalleh\FilamentShield\Traits\HasShieldFormComponents;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Pages\SubNavigationPosition;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class RoleResource extends Resource implements HasShieldPermissions
{
    use HasShieldFormComponents;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getPermissionPrefixes(): array
    {
        return [
            'view',
            'view_any',
            'create',
            'update',
            'delete',
            'delete_any',
        ];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make()
                    ->schema([
                        Forms\Components\Section::make()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('filament-shield::filament-shield.field.name'))
                                    ->unique(
                                        ignoreRecord: true, /** @phpstan-ignore-next-line */
                                        modifyRuleUsing: fn (Unique $rule) => Utils::isTenancyEnabled() ? $rule->where(Utils::getTenantModelForeignKey(), Filament::getTenant()?->id) : $rule
                                    )
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('guard_name')
                                    ->label(__('filament-shield::filament-shield.field.guard_name'))
                                    ->default(Utils::getFilamentAuthGuard())
                                    ->nullable()
                                    ->maxLength(255),

                                Forms\Components\Select::make(config('permission.column_names.team_foreign_key'))
                                    ->label(__('filament-shield::filament-shield.field.team'))
                                    ->placeholder(__('filament-shield::filament-shield.field.team.placeholder'))
                                    /** @phpstan-ignore-next-line */
                                    ->default([Filament::getTenant()?->id])
                                    ->options(fn (): Arrayable => Utils::getTenantModel() ? Utils::getTenantModel()::pluck('name', 'id') : collect())
                                    ->hidden(fn (): bool => ! (static::shield()->isCentralApp() && Utils::isTenancyEnabled()))
                                    ->dehydrated(fn (): bool => ! (static::shield()->isCentralApp() && Utils::isTenancyEnabled())),
                                ShieldSelectAllToggle::make('select_all')
                                    ->onIcon('heroicon-s-shield-check')
                                    ->offIcon('heroicon-s-shield-exclamation')
                                    ->label(__('filament-shield::filament-shield.field.select_all.name'))
                                    ->helperText(fn (): HtmlString => new HtmlString(__('filament-shield::filament-shield.field.select_all.message')))
                                    ->dehydrated(fn (bool $state): bool => $state),

                            ])
                            ->columns([
                                'sm' => 2,
                                'lg' => 3,
                            ]),
                    ]),
                static::getShieldFormComponents(),
            ]);
    }

    /**
     * Override del trait: senza "Visualizza" (view/view_any) le altre azioni
     * su una risorsa (modifica, elimina, ecc.) sono irraggiungibili dall'utente,
     * quindi la checkbox list si autocorregge in tempo reale.
     */
    /**
     * Le 24 risorse in un'unica colonna erano sette schermate di scroll, con
     * l'ordine alfabetico del nome del model: "Automezzo" accanto a "Utente",
     * "Listino" lontano da "Materiale". Qui sono divise per area come in
     * sidebar, cosi' chi assegna un ruolo apre l'area che gli interessa e
     * vede tre o quattro riquadri invece di ventiquattro.
     *
     * L'ordine delle aree e' quello dei NavigationGroup del pannello: non e'
     * alfabetico, segue il flusso del lavoro (prima si vende, poi si
     * interviene, poi si amministra).
     */
    private const AREE = [
        'Vendite',
        'Catalogo',
        'Interventi tecnici',
        'Magazzino',
        'Personale',
        'Amministrazione',
        'Impostazioni',
    ];

    public static function getTabFormComponentForResources(): Component
    {
        return Forms\Components\Tabs\Tab::make('resources')
            ->label(__('filament-shield::filament-shield.resources'))
            ->visible(fn (): bool => (bool) Utils::isResourceEntityEnabled())
            ->badge(static::getResourceTabBadgeCount())
            ->schema([
                Forms\Components\Placeholder::make('promemoria_visualizza')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<p class="text-sm text-gray-500 dark:text-gray-400">Senza <strong>Vedere l\'elenco</strong> e <strong>Aprire la scheda</strong> le altre azioni non sono raggiungibili: restano disattivate finche\' non spunti quelle due.</p>'
                    )),
                Forms\Components\Tabs::make('aree')
                    ->contained(false)
                    ->tabs(static::schedeDelleAree())
                    ->columnSpan('full'),
            ]);
    }

    /**
     * Una scheda per area, con dentro i riquadri delle sue risorse. Le aree
     * senza risorse non compaiono, e quello che non ha un NavigationGroup
     * (o ne ha uno che non conosciamo) finisce in coda sotto "Altro" invece
     * di sparire.
     *
     * @return array<int, Forms\Components\Tabs\Tab>
     */
    protected static function schedeDelleAree(): array
    {
        $perArea = collect(FilamentShield::getResources())
            ->sortBy(fn (array $entity) => static::etichettaRisorsa($entity))
            ->groupBy(fn (array $entity) => static::areaDi($entity['fqcn']));

        $ordine = collect(self::AREE)->push('Altro');

        return $ordine
            ->filter(fn (string $area) => $perArea->has($area))
            ->map(fn (string $area) => Forms\Components\Tabs\Tab::make($area)
                ->badge($perArea[$area]->count())
                ->schema([
                    Forms\Components\Grid::make()
                        ->schema(
                            $perArea[$area]
                                ->map(fn (array $entity) => static::riquadroRisorsa($entity))
                                ->all()
                        )
                        ->columns(static::shield()->getGridColumns()),
                ]))
            ->values()
            ->all();
    }

    protected static function riquadroRisorsa(array $entity): Component
    {
        return Forms\Components\Section::make(static::etichettaRisorsa($entity))
            ->compact()
            ->collapsible()
            ->columnSpan(static::shield()->getSectionColumnSpan())
            ->schema([
                static::getCheckBoxListComponentForResource($entity),
            ]);
    }

    protected static function etichettaRisorsa(array $entity): string
    {
        return strval(
            static::shield()->hasLocalizedPermissionLabels()
                ? FilamentShield::getLocalizedResourceLabel($entity['fqcn'])
                : $entity['model']
        );
    }

    /** Il gruppo di sidebar della risorsa, o "Altro" se non ne ha uno noto. */
    protected static function areaDi(string $fqcn): string
    {
        $area = method_exists($fqcn, 'getNavigationGroup')
            ? (string) $fqcn::getNavigationGroup()
            : '';

        return in_array($area, self::AREE, true) ? $area : 'Altro';
    }

    /**
     * Le sole risorse col soft delete. Sulle altre "ripristina" ed "elimina
     * definitivamente" sono caselle che non accendono niente: nascoste.
     * Da tenere in fase con RolePermissions::COL_CESTINO.
     */
    private const COL_CESTINO = [
        'customer',
        'machine::unit',
        'quote',
        'quote::group',
        'service::report',
        'tenant',
    ];

    private const SOLO_COL_CESTINO = ['restore', 'restore_any', 'force_delete', 'force_delete_any'];

    /**
     * In italiano e dette come le vive chi usa il pannello: la traduzione del
     * pacchetto lascia queste chiavi commentate, quindi Shield ripiegava su
     * "View Any", "Force Delete Any" e simili.
     */
    private const ETICHETTE = [
        'view_any' => 'Vedere l\'elenco',
        'view' => 'Aprire la scheda',
        'create' => 'Creare',
        'update' => 'Modificare',
        'delete' => 'Cestinare',
        'delete_any' => 'Cestinare in blocco',
        'restore' => 'Ripristinare dal cestino',
        'restore_any' => 'Ripristinare in blocco',
        'force_delete' => 'Eliminare per sempre',
        'force_delete_any' => 'Eliminare per sempre in blocco',
    ];

    /**
     * Ordine di lettura: prima cosa si vede, poi cosa si tocca, poi cosa si
     * distrugge. L'ordine dei prefissi in config e' quello del pacchetto.
     */
    private const ORDINE = [
        'view_any', 'view', 'create', 'update',
        'delete', 'delete_any', 'restore', 'restore_any',
        'force_delete', 'force_delete_any',
    ];

    /** @return array<string, string> */
    public static function getResourcePermissionOptions(array $entity): array
    {
        $risorsa = $entity['resource'];
        $conCestino = in_array($risorsa, self::COL_CESTINO, true);

        // I prefissi in vigore per questa risorsa: quelli di config, salvo
        // che la risorsa non ne dichiari di suoi con HasShieldPermissions.
        $attivi = Utils::getResourcePermissionPrefixes($entity['fqcn']);

        $opzioni = [];

        foreach (self::ORDINE as $prefisso) {
            if (! in_array($prefisso, $attivi, true)) {
                continue;
            }

            if (! $conCestino && in_array($prefisso, self::SOLO_COL_CESTINO, true)) {
                continue;
            }

            $opzioni["{$prefisso}_{$risorsa}"] = self::ETICHETTE[$prefisso];
        }

        // Un prefisso che la risorsa dichiara ma che qui non abbiamo tradotto
        // non deve sparire dalla schermata: finirebbe fuori da ogni controllo.
        foreach ($attivi as $prefisso) {
            $nome = "{$prefisso}_{$risorsa}";

            if (! array_key_exists($nome, $opzioni) && ! array_key_exists($prefisso, self::ETICHETTE)) {
                $opzioni[$nome] = Str::headline($prefisso);
            }
        }

        return $opzioni;
    }

    public static function getCheckBoxListComponentForResource(array $entity): Component
    {
        $permissionsArray = static::getResourcePermissionOptions($entity);
        $resource = $entity['resource'];

        $viewAnyKey = "view_any_{$resource}";
        $viewKey = "view_{$resource}";

        return static::getCheckboxListFormComponent(
            name: $resource,
            options: $permissionsArray,
            columns: static::shield()->getResourceCheckboxListColumns(),
            columnSpan: static::shield()->getResourceCheckboxListColumnSpan(),
            searchable: false
        )
            ->live()
            ->afterStateUpdated(
                fn (Forms\Set $set, ?array $state) => $set(
                    $resource,
                    static::enforceViewDependencyForResourcePermissions($resource, $state ?? [])
                )
            )
            ->disableOptionWhen(
                fn (string $value, ?array $state) => $value !== $viewAnyKey
                    && $value !== $viewKey
                    && ! (collect($state ?? [])->contains($viewAnyKey) && collect($state ?? [])->contains($viewKey))
            )
;
    }

    /**
     * Rimuove dallo stato selezionato i permessi che non hanno senso senza le
     * relative permission di visualizzazione: a parte "view_any" e "view"
     * stessi, ogni altro permesso (create, update, delete, ...) richiede che
     * siano attive entrambe, perche' senza "Visualizza" la risorsa non e'
     * raggiungibile in UI.
     *
     * @param  array<int, string>  $state
     * @return array<int, string>
     */
    protected static function enforceViewDependencyForResourcePermissions(string $resource, array $state): array
    {
        $selected = collect($state);

        $viewAnyKey = "view_any_{$resource}";
        $viewKey = "view_{$resource}";

        $hasViewAny = $selected->contains($viewAnyKey);
        $hasView = $selected->contains($viewKey);

        return $selected
            ->filter(function (string $permission) use ($viewAnyKey, $viewKey, $hasViewAny, $hasView) {
                if ($permission === $viewAnyKey || $permission === $viewKey) {
                    return true;
                }

                return $hasViewAny && $hasView;
            })
            ->values()
            ->toArray();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->weight('font-medium')
                    ->label(__('filament-shield::filament-shield.column.name'))
                    ->formatStateUsing(fn ($state): string => Str::headline($state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('guard_name')
                    ->badge()
                    ->color('warning')
                    ->label(__('filament-shield::filament-shield.column.guard_name')),
                Tables\Columns\TextColumn::make('team.name')
                    ->default('Global')
                    ->badge()
                    ->color(fn (mixed $state): string => str($state)->contains('Global') ? 'gray' : 'primary')
                    ->label(__('filament-shield::filament-shield.column.team'))
                    ->searchable()
                    ->visible(fn (): bool => static::shield()->isCentralApp() && Utils::isTenancyEnabled()),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->badge()
                    ->label(__('filament-shield::filament-shield.column.permissions'))
                    ->counts('permissions')
                    ->colors(['success']),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('filament-shield::filament-shield.column.updated_at'))
                    ->dateTime(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'view' => Pages\ViewRole::route('/{record}'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    public static function getCluster(): ?string
    {
        return Utils::getResourceCluster() ?? static::$cluster;
    }

    public static function getModel(): string
    {
        return Utils::getRoleModel();
    }

    public static function getModelLabel(): string
    {
        return __('filament-shield::filament-shield.resource.label.role');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-shield::filament-shield.resource.label.roles');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Utils::isResourceNavigationRegistered();
    }

    public static function getNavigationGroup(): ?string
    {
        // Sotto "Impostazioni" invece del gruppo dedicato "Filament Shield"
        // di default: e' l'unica risorsa del plugin, non serve un gruppo
        // a se stante in sidebar.
        return 'Impostazioni';
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-shield::filament-shield.nav.role.label');
    }

    public static function getNavigationIcon(): string
    {
        return __('filament-shield::filament-shield.nav.role.icon');
    }

    public static function getNavigationSort(): ?int
    {
        return Utils::getResourceNavigationSort();
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return Utils::getSubNavigationPosition() ?? static::$subNavigationPosition;
    }

    public static function getSlug(): string
    {
        return Utils::getResourceSlug();
    }

    public static function getNavigationBadge(): ?string
    {
        return null;
    }

    public static function isScopedToTenant(): bool
    {
        return Utils::isScopedToTenant();
    }

    public static function canGloballySearch(): bool
    {
        return Utils::isResourceGloballySearchable() && count(static::getGloballySearchableAttributes()) && static::canViewAny();
    }
}
