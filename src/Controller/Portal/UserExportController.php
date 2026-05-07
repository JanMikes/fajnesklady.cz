<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use App\Service\Excel\ExcelColumn;
use App\Service\Excel\ExcelColumnType;
use App\Service\Excel\ExcelExporter;
use App\Service\Excel\ExcelSheet;
use App\Service\Overdue\OverdueChecker;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/users/export', name: 'portal_users_export')]
#[IsGranted('ROLE_ADMIN')]
final class UserExportController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly OverdueChecker $overdueChecker,
        private readonly ExcelExporter $excelExporter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $now = $this->clock->now();
        $filterParam = $request->query->get('filter');
        $filter = match ($filterParam) {
            'overdue', 'onboarded', 'active', 'inactive' => $filterParam,
            default => null,
        };

        $columns = [
            new ExcelColumn('Jméno'),
            new ExcelColumn('E-mail'),
            new ExcelColumn('Telefon'),
            new ExcelColumn('Role'),
            new ExcelColumn('Aktivní', ExcelColumnType::BOOLEAN),
            new ExcelColumn('Ověřený', ExcelColumnType::BOOLEAN),
            new ExcelColumn('Onboardovaný', ExcelColumnType::BOOLEAN),
            new ExcelColumn('Dlužník', ExcelColumnType::BOOLEAN),
            new ExcelColumn('IČO'),
            new ExcelColumn('DIČ'),
            new ExcelColumn('Adresa'),
            new ExcelColumn('Registrován', ExcelColumnType::DATE),
            new ExcelColumn('Poslední přihlášení', ExcelColumnType::DATETIME),
        ];

        // Pre-compute debtor / onboarded membership sets once per request. The
        // user table is bounded by total users (~hundreds), not orders, so the
        // single ID round-trip pays for itself versus N per-row queries inside
        // the row-streaming loop. See spec 028 §6.C.
        $allIds = $this->userRepository->findIdsForExport($filter, $now);
        $debtorIdSet = array_flip($this->overdueChecker->filterOverdueUserIds($now, $allIds));
        $onboardedIdSet = array_flip($this->userRepository->findOnboardedUserIds($allIds));

        $users = $this->userRepository->streamForExport($filter, $now);
        $rows = (static function () use ($users, $debtorIdSet, $onboardedIdSet): \Generator {
            foreach ($users as $user) {
                /* @var User $user */
                $userId = (string) $user->id;
                yield [
                    $user->fullName,
                    $user->email,
                    $user->phone,
                    self::renderRoles($user->getRoles()),
                    !$user->isDeactivated(),
                    $user->isVerified(),
                    isset($onboardedIdSet[$userId]),
                    isset($debtorIdSet[$userId]),
                    $user->companyId,
                    $user->companyVatId,
                    self::renderAddress($user),
                    $user->createdAt,
                    $user->lastLoginAt,
                ];
            }
        })();

        $slug = null !== $filter ? sprintf('-%s', $filter) : '';
        $sheet = new ExcelSheet(
            sheetTitle: 'Uživatelé',
            filename: sprintf('uzivatele%s-%s.xlsx', $slug, $now->format('Y-m-d')),
            columns: $columns,
            rows: $rows,
        );

        return $this->excelExporter->stream($sheet);
    }

    /**
     * @param string[] $roles
     */
    private static function renderRoles(array $roles): string
    {
        $labels = [];
        foreach ($roles as $role) {
            $userRole = UserRole::tryFrom($role);
            if (null !== $userRole) {
                $labels[] = $userRole->label();
            }
        }

        return implode(', ', array_unique($labels));
    }

    private static function renderAddress(User $user): string
    {
        $parts = array_filter([
            $user->billingStreet,
            trim(sprintf('%s %s', (string) $user->billingPostalCode, (string) $user->billingCity)),
        ], static fn (?string $part): bool => null !== $part && '' !== trim($part));

        return implode(', ', $parts);
    }
}
