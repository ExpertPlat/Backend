<?php

namespace App\Enums;

enum AdminPermission: string
{
    case AdminsViewAny = 'admins.viewAny';
    case AdminsView = 'admins.view';
    case AdminsCreate = 'admins.create';
    case AdminsUpdate = 'admins.update';
    case AdminsAssignRoles = 'admins.assignRoles';

    case RolesViewAny = 'roles.viewAny';
    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';
    case PermissionsViewAny = 'permissions.viewAny';

    case UsersViewAny = 'users.viewAny';
    case UsersView = 'users.view';
    case UsersUpdate = 'users.update';
    case UsersConsentMetadataView = 'users.consentMetadata.view';
    case DataRequestsViewAny = 'dataRequests.viewAny';
    case DataRequestsView = 'dataRequests.view';

    case ExpertsViewAny = 'experts.viewAny';
    case ExpertsView = 'experts.view';
    case ExpertsUpdate = 'experts.update';
    case ExpertsReviewKyc = 'experts.reviewKyc';
    case ExpertRenewalsViewAny = 'expertRenewals.viewAny';
    case ExpertRenewalsView = 'expertRenewals.view';
    case ExpertRenewalsViewEvidence = 'expertRenewals.viewEvidence';
    case ExpertRenewalsReview = 'expertRenewals.review';

    case CatalogView = 'catalog.view';
    case CatalogManageDrafts = 'catalog.manageDrafts';
    case CatalogReview = 'catalog.review';
    case CatalogPublish = 'catalog.publish';
    case CatalogPause = 'catalog.pause';
    case CatalogViewImpact = 'catalog.viewImpact';

    case CasesViewAny = 'cases.viewAny';
    case CasesView = 'cases.view';

    case FinanceView = 'finance.view';
    case FinanceReconcile = 'finance.reconcile';
    case RefundsView = 'refunds.view';
    case RefundsApprove = 'refunds.approve';
    case DisputesView = 'disputes.view';
    case StripeAccountsView = 'stripe_accounts.view';

    case ReviewsViewAny = 'reviews.viewAny';
    case ReviewsModerate = 'reviews.moderate';

    case SupportViewAny = 'support.viewAny';
    case SupportView = 'support.view';
    case SupportUpdate = 'support.update';
}
