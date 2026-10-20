<?php

declare(strict_types=1);

namespace BsCms\Operations;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Content\EntryRepository;
use BsCms\Content\VersionManager;
use BsCms\Database\Connection;
use BsCms\Health\SiteHealthService;
use BsCms\Media\MediaRepository;
use BsCms\Operations\Content\CreateEntryOperation;
use BsCms\Operations\Content\DeleteEntryOperation;
use BsCms\Operations\Content\GetEntryOperation;
use BsCms\Operations\Content\ListVersionsOperation;
use BsCms\Operations\Content\PublishEntryOperation;
use BsCms\Operations\Content\QueryEntriesOperation;
use BsCms\Operations\Content\RestoreVersionOperation;
use BsCms\Operations\Content\ScheduleEntryOperation;
use BsCms\Operations\Content\UnpublishEntryOperation;
use BsCms\Operations\Content\UpdateEntryOperation;
use BsCms\Operations\Media\ListMediaOperation;
use BsCms\Operations\Media\UpdateMediaOperation;
use BsCms\Operations\Media\UploadMediaFromUrlOperation;
use BsCms\Operations\Schema\ApplySchemaChangeOperation;
use BsCms\Operations\Schema\DescribeCollectionOperation;
use BsCms\Operations\Schema\ListCollectionsOperation;
use BsCms\Operations\Schema\ProposeSchemaChangeOperation;
use BsCms\Operations\Schema\SiteOverviewOperation;
use BsCms\Operations\System\CreateRedirectOperation;
use BsCms\Operations\System\GetAuditLogOperation;
use BsCms\Operations\System\GetDeployStatusOperation;
use BsCms\Operations\System\GetSiteHealthOperation;
use BsCms\Operations\System\ListPendingChangesOperation;
use BsCms\Operations\System\ListRedirectsOperation;
use BsCms\Operations\System\TriggerDeployOperation;
use BsCms\Operations\Taxonomy\CreateTermOperation;
use BsCms\Operations\Taxonomy\ListTaxonomiesOperation;
use BsCms\Operations\Taxonomy\ListTermsOperation;
use BsCms\Operations\Taxonomy\MoveTermOperation;
use BsCms\Operations\Taxonomy\UpdateTermOperation;
use BsCms\Redirects\RedirectRepository;
use BsCms\Schema\CollectionRegistry;
use BsCms\Settings\SettingsRepository;
use BsCms\Taxonomy\TaxonomyRepository;
use BsCms\Taxonomy\TermRepository;

final class OperationsRegistrar
{
    public static function registerAll(
        Dispatcher $dispatcher,
        CollectionRegistry $registry,
        EntryRepository $entryRepository,
        VersionManager $versionManager,
        Authorizer $authorizer,
        Connection $connection,
        TaxonomyRepository $taxonomyRepository,
        TermRepository $termRepository,
        MediaRepository $mediaRepository,
        SiteHealthService $siteHealthService,
        RedirectRepository $redirectRepository,
        AuditLogger $auditLogger,
        ?SettingsRepository $settings = null
    ): void {
        // 1. Schema operations
        $dispatcher->register(SiteOverviewOperation::name(), new SiteOverviewOperation($registry, $authorizer, $connection));
        $dispatcher->register(ListCollectionsOperation::name(), new ListCollectionsOperation($registry, $authorizer));
        $dispatcher->register(DescribeCollectionOperation::name(), new DescribeCollectionOperation($registry, $authorizer));
        $dispatcher->register(ProposeSchemaChangeOperation::name(), new ProposeSchemaChangeOperation($registry, $authorizer));
        $dispatcher->register(ApplySchemaChangeOperation::name(), new ApplySchemaChangeOperation($registry, $authorizer));

        // 2. Content operations
        $dispatcher->register(QueryEntriesOperation::name(), new QueryEntriesOperation($registry, $entryRepository, $authorizer));
        $dispatcher->register(GetEntryOperation::name(), new GetEntryOperation($registry, $entryRepository, $authorizer));
        $dispatcher->register(CreateEntryOperation::name(), new CreateEntryOperation($registry, $entryRepository, $authorizer));
        $dispatcher->register(UpdateEntryOperation::name(), new UpdateEntryOperation($registry, $entryRepository, $authorizer));
        $dispatcher->register(PublishEntryOperation::name(), new PublishEntryOperation($registry, $entryRepository, $authorizer));
        $dispatcher->register(UnpublishEntryOperation::name(), new UnpublishEntryOperation($registry, $entryRepository, $authorizer));
        $dispatcher->register(ScheduleEntryOperation::name(), new ScheduleEntryOperation($registry, $entryRepository, $authorizer));
        $dispatcher->register(DeleteEntryOperation::name(), new DeleteEntryOperation($registry, $entryRepository, $authorizer));
        $dispatcher->register(ListVersionsOperation::name(), new ListVersionsOperation($registry, $versionManager, $authorizer));
        $dispatcher->register(RestoreVersionOperation::name(), new RestoreVersionOperation($registry, $entryRepository, $versionManager, $authorizer));

        // 3. Taxonomy operations
        $dispatcher->register(ListTaxonomiesOperation::name(), new ListTaxonomiesOperation($taxonomyRepository, $authorizer));
        $dispatcher->register(ListTermsOperation::name(), new ListTermsOperation($taxonomyRepository, $termRepository, $authorizer));
        $dispatcher->register(CreateTermOperation::name(), new CreateTermOperation($taxonomyRepository, $termRepository, $authorizer));
        $dispatcher->register(UpdateTermOperation::name(), new UpdateTermOperation($termRepository, $authorizer));
        $dispatcher->register(MoveTermOperation::name(), new MoveTermOperation($termRepository, $authorizer));

        // 4. Media operations
        $dispatcher->register(ListMediaOperation::name(), new ListMediaOperation($mediaRepository, $authorizer));
        $dispatcher->register(UpdateMediaOperation::name(), new UpdateMediaOperation($mediaRepository, $authorizer));
        $dispatcher->register(UploadMediaFromUrlOperation::name(), new UploadMediaFromUrlOperation($mediaRepository, $authorizer));

        // 5. System operations
        $dispatcher->register(GetSiteHealthOperation::name(), new GetSiteHealthOperation($siteHealthService, $authorizer));
        $dispatcher->register(TriggerDeployOperation::name(), new TriggerDeployOperation($authorizer, $settings));
        $dispatcher->register(GetDeployStatusOperation::name(), new GetDeployStatusOperation($authorizer, $settings));
        $dispatcher->register(ListRedirectsOperation::name(), new ListRedirectsOperation($redirectRepository, $authorizer));
        $dispatcher->register(CreateRedirectOperation::name(), new CreateRedirectOperation($redirectRepository, $authorizer));
        $dispatcher->register(GetAuditLogOperation::name(), new GetAuditLogOperation($auditLogger, $authorizer));
        $dispatcher->register(ListPendingChangesOperation::name(), new ListPendingChangesOperation($connection, $authorizer));
    }
}
