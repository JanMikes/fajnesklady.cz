<?php

declare(strict_types=1);

namespace App\Query;

use App\Repository\PaymentRepository;
use App\Repository\PlaceRepository;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GetPlaceRevenueChartQuery
{
    private const array MONTH_NAMES = [
        1 => 'leden',
        2 => 'únor',
        3 => 'březen',
        4 => 'duben',
        5 => 'květen',
        6 => 'červen',
        7 => 'červenec',
        8 => 'srpen',
        9 => 'září',
        10 => 'říjen',
        11 => 'listopad',
        12 => 'prosinec',
    ];

    public function __construct(
        private PlaceRepository $placeRepository,
        private UserRepository $userRepository,
        private PaymentRepository $paymentRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GetPlaceRevenueChart $query): GetPlaceRevenueChartResult
    {
        $place = $this->placeRepository->get($query->placeId);
        $owner = null !== $query->landlordId
            ? $this->userRepository->get($query->landlordId)
            : null;
        $now = $this->clock->now();

        $data = $this->paymentRepository->getMonthlyRevenueAtPlace($place, $query->months, $now, $owner);

        $labels = [];
        $revenues = [];

        $monthsData = $this->fillMissingMonths($data, $query->months, $now);

        foreach ($monthsData as $row) {
            $labels[] = self::MONTH_NAMES[$row['month']].' '.$row['year'];
            $revenues[] = $row['total'];
        }

        return new GetPlaceRevenueChartResult(
            labels: $labels,
            revenues: $revenues,
        );
    }

    /**
     * @param array<array{year: int, month: int, total: int}> $data
     *
     * @return array<array{year: int, month: int, total: int}>
     */
    private function fillMissingMonths(array $data, int $months, \DateTimeImmutable $now): array
    {
        $dataByKey = [];
        foreach ($data as $row) {
            $key = sprintf('%d-%02d', $row['year'], $row['month']);
            $dataByKey[$key] = $row['total'];
        }

        $result = [];
        $date = $now->modify("-{$months} months")->modify('first day of this month');

        for ($i = 0; $i < $months; ++$i) {
            $year = (int) $date->format('Y');
            $month = (int) $date->format('n');
            $key = sprintf('%d-%02d', $year, $month);

            $result[] = [
                'year' => $year,
                'month' => $month,
                'total' => $dataByKey[$key] ?? 0,
            ];

            $date = $date->modify('+1 month');
        }

        return $result;
    }
}
