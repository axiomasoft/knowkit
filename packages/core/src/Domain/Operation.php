<?php
declare(strict_types=1);

namespace KnowKit\Domain;

enum Operation: string
{
    case Retrieve = 'retrieve';
    case Disclose = 'disclose';
    case Publish = 'publish';
    case ModelEgressContext = 'model_egress_context';
    case Navigate = 'navigate';
    case CatalogCreate = 'catalog_create';
    case CollectionCreate = 'collection_create';
    case MembershipMutate = 'membership_mutate';
    case SourceRegister = 'source_register';
    case SourceVersionAdd = 'source_version_add';
    case Ingest = 'ingest';
    case Reprocess = 'reprocess';
    case JobCancel = 'job_cancel';
    case LifecycleCorrect = 'lifecycle_correct';
    case LifecycleRevoke = 'lifecycle_revoke';
    case LifecycleDelete = 'lifecycle_delete';
    case LegalHoldSet = 'legal_hold_set';
    case PurgeStatus = 'purge_status';
    case ProviderAcceptance = 'provider_acceptance';
    case Diagnostics = 'diagnostics';
    case LayerResolve = 'layer_resolve';
    case DirectivePromote = 'directive_promote';
    case CatalogMount = 'catalog_mount';
    case TrainingExport = 'training_export';
}
