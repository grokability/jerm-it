<?php

namespace App\Actions\CheckoutRequests;

use App\Helpers\Helper;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\RequestAssetCancelation;
use App\Notifications\RequestAssetNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateCheckoutRequest
{
    use AsAction;

    public string $status;

    public function handle($assetId)
    {
        $user = auth()->user();

        // Check if the asset exists and is requestable
        if (is_null($asset = Asset::RequestableAssets()->find($assetId))) {
            $this->status = 'doesNotExist';
            return false;
        }
        if (!Company::isCurrentUserHasAccess($asset)) {
            $this->status = 'accessDenied';
            return false;
        }

        $data['item'] = $asset;
        $data['target'] = auth()->user();
        $data['item_quantity'] = 1;
        $settings = Setting::getSettings();

        $logaction = new Actionlog();
        $logaction->item_id = $data['asset_id'] = $asset->id;
        $logaction->item_type = $data['item_type'] = Asset::class;
        $logaction->created_at = $data['requested_date'] = date('Y-m-d H:i:s');

        if ($user->location_id) {
            $logaction->location_id = $user->location_id;
        }
        $logaction->target_id = $data['user_id'] = auth()->id();
        $logaction->target_type = User::class;

        // If it's already requested, cancel the request.
        if ($asset->isRequestedBy(auth()->user())) {
            $asset->cancelRequest();
            $asset->decrement('requests_counter', 1);

            $logaction->logaction('request canceled');
            try {
                $settings->notify(new RequestAssetCancelation($data));
            } catch (\Exception $e) {
                Log::warning($e);
            }
            $this->status = 'cancelled';
            return true;
        }

        $logaction->logaction('requested');
        $asset->request();
        $asset->increment('requests_counter', 1);
        try {
            $settings->notify(new RequestAssetNotification($data));
        } catch (\Exception $e) {
            \Log::warning($e);
        }

        return true;
    }

    public function jsonResponse(): JsonResponse
    {
        return match ($this->status) {
            'doesNotExist' => response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/hardware/message.does_not_exist_or_not_requestable'))),
            'accessDenied' => response()->json(Helper::formatStandardApiResponse('error', null, trans('general.insufficient_permissions'))),
            default => response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/hardware/message.request_successfully_created'))),
        };
    }

    public function htmlResponse(): RedirectResponse
    {
        return match ($this->status) {
            'doesNotExist' => redirect()->route('requestable-assets')->with('error', trans('admin/hardware/message.does_not_exist_or_not_requestable')),
            'accessDenied' => redirect()->route('requestable-assets')->with('error', trans('general.insufficient_permissions')),
            'cancelled' => redirect()->route('requestable-assets')->with('success')->with('success', trans('admin/hardware/message.requests.canceled')),
            default => redirect()->route('requestable-assets')->with('success')->with('success', trans('admin/hardware/message.requests.success')),
        };
    }
}
