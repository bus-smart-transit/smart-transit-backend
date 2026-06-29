<?php

namespace App\Services;

use App\Repositories\TicketRepository;
use App\Http\Resources\TicketResource;

class TicketService
{
    private TicketRepository $ticketRepository;

    public function __construct(TicketRepository $ticketRepository) 
    {
        $this->ticketRepository = $ticketRepository;
    }

    public function listTicket(int $perPage = 15)
    {
        $collection = $this->ticketRepository->paginate($perPage);
        return TicketResource::collection($collection);
    }

    public function createTicket(array $payload)
    {
        $model = $this->ticketRepository->create($payload);
        
    }

    public function getTicket(string $uuid)
    {
        $model = $this->ticketRepository->findByUuid($uuid);
        
    }

    public function getTicketByField(string $field, $value)
    {
        $model = $this->ticketRepository->findByField($field, $value);
        
    }

    public function updateTicket(string $uuid, array $payload)
    {
        $model = $this->ticketRepository->update($uuid, $payload);
        
    }

    public function deleteTicket(string $uuid)
    {
        $this->ticketRepository->delete($uuid);
        return true;
    }

    public function restoreTicket(string $uuid)
    {
        $model = $this->ticketRepository->restore($uuid);
        
    }
}