<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Query\GetAdminRevenueChart;
use App\Query\GetLandlordRevenueChart;
use App\Query\QueryBus;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class RevenueChart
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public int $months = 12;

    #[LiveProp]
    public ?string $landlordId = null;

    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly ChartBuilderInterface $chartBuilder,
    ) {
    }

    public function getChart(): Chart
    {
        if (null !== $this->landlordId) {
            $result = $this->queryBus->handle(new GetLandlordRevenueChart(
                landlordId: Uuid::fromString($this->landlordId),
                months: $this->months,
            ));
        } else {
            $result = $this->queryBus->handle(new GetAdminRevenueChart(
                months: $this->months,
            ));
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);

        $revenuesInCzk = array_map(static fn(int $h): float => $h / 100, $result->revenues);

        $chart->setData([
            'labels' => $result->labels,
            'datasets' => [
                [
                    'label' => 'Tržby (Kč)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.5)',
                    'borderColor' => 'rgb(59, 130, 246)',
                    'borderWidth' => 1,
                    'data' => $revenuesInCzk,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
        ]);

        return $chart;
    }
}
