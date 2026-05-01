<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailLog;
use App\Exception\EmailLogNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Symfony\Component\Uid\Uuid;

class EmailLogRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Persists and flushes the email log immediately.
     *
     * Unlike most repositories in this project, this one calls flush() explicitly.
     * The mailer listener fires during transport send — this happens outside the
     * command bus's doctrine_transaction middleware (e.g. mid-request in any
     * controller, console command, or messenger worker), so there is no
     * surrounding transaction that will flush for us.
     */
    public function save(EmailLog $log): void
    {
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    public function find(Uuid $id): ?EmailLog
    {
        return $this->entityManager->find(EmailLog::class, $id);
    }

    public function get(Uuid $id): EmailLog
    {
        $log = $this->find($id);

        if (null === $log) {
            throw EmailLogNotFound::withId($id);
        }

        return $log;
    }

    /**
     * @return EmailLog[]
     */
    public function findPaginated(int $page, int $limit, EmailLogFilter $filter): array
    {
        $offset = ($page - 1) * $limit;

        // Native SQL is used so we can ILIKE the JSONB recipient column with `::text`.
        // DQL does not expose Postgres-specific casts.
        $rsm = new ResultSetMappingBuilder($this->entityManager);
        $rsm->addRootEntityFromClassMetadata(EmailLog::class, 'el');

        $sql = 'SELECT '.$rsm->generateSelectClause(['el' => 'el'])
            .' FROM email_log el';

        [$where, $params] = $this->buildWhere($filter);
        if ('' !== $where) {
            $sql .= ' WHERE '.$where;
        }

        $sql .= ' ORDER BY el.attempted_at DESC LIMIT :limit OFFSET :offset';

        $query = $this->entityManager->createNativeQuery($sql, $rsm);
        foreach ($params as $name => $value) {
            $query->setParameter($name, $value);
        }
        $query->setParameter('limit', $limit);
        $query->setParameter('offset', $offset);

        return $query->getResult();
    }

    public function countWithFilter(EmailLogFilter $filter): int
    {
        $sql = 'SELECT COUNT(*) FROM email_log el';

        [$where, $params] = $this->buildWhere($filter);
        if ('' !== $where) {
            $sql .= ' WHERE '.$where;
        }

        return (int) $this->entityManager->getConnection()->fetchOne($sql, $params);
    }

    /**
     * @return string[]
     */
    public function getDistinctTemplateNames(): array
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT el.templateName')
            ->from(EmailLog::class, 'el')
            ->where('el.templateName IS NOT NULL')
            ->orderBy('el.templateName', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_filter(array_column($result, 'templateName')));
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(EmailLogFilter $filter): array
    {
        $conditions = [];
        $params = [];

        if (null !== $filter->dateFrom) {
            $conditions[] = 'el.attempted_at >= :dateFrom';
            $params['dateFrom'] = $filter->dateFrom->format('Y-m-d H:i:s');
        }

        if (null !== $filter->dateTo) {
            $conditions[] = 'el.attempted_at <= :dateTo';
            $params['dateTo'] = $filter->dateTo->format('Y-m-d H:i:s');
        }

        if (null !== $filter->recipient && '' !== $filter->recipient) {
            $conditions[] = 'el.to_addresses::text ILIKE :recipient';
            $params['recipient'] = '%'.$filter->recipient.'%';
        }

        if (null !== $filter->subject && '' !== $filter->subject) {
            $conditions[] = 'el.subject ILIKE :subject';
            $params['subject'] = '%'.$filter->subject.'%';
        }

        if (null !== $filter->templateName && '' !== $filter->templateName) {
            $conditions[] = 'el.template_name = :templateName';
            $params['templateName'] = $filter->templateName;
        }

        if (null !== $filter->status) {
            $conditions[] = 'el.status = :status';
            $params['status'] = $filter->status->value;
        }

        return [implode(' AND ', $conditions), $params];
    }
}
