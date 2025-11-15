//Task Optimization
<?php

namespace App\Http\Controllers;

use App\Models\DocumentViewsModel;
use App\Models\ShareDocs;
use App\Models\User;
use App\Models\DocsModel;
use App\Models\Sign;
use App\Models\UserVerificationDoc;
use App\Models\Survey;
use App\Models\Questionset;
use App\Models\ClientExtraDetail;
use App\Support\AppSupport;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ClientControllerNew extends Controller
{
    /**
     * OPTIMIZED VERSION - Preserves identical output but optimized for performance
     */
    public function view(Request $request)
    {
        $company = AppSupport::getCompanyOfAuthUser();
        
        if (!$request->has('phone')) {
            return redirect('dashboard')->with('error', 'Invalid Request');
        }

        $phone = $request->phone;

        // OPTIMIZATION: Get client in single query
        $client = User::where('phone', $phone)->first();
        if (!$client) {
            return redirect('dashboard')->with('error', 'Client not found');
        }

        // OPTIMIZATION: Use eager loading and batch processing
        $clientId = $client->id;
        $companyId = $company->id;

        // Get all document views with relationships in single query
        $documentViewsDocs = DocumentViewsModel::with(['docsData'])
            ->where('user_id', $clientId)
            ->where('sender_id', $companyId)
            ->where(function ($query) {
                $query->where('status', 'accepted')
                    ->orWhere('status', 'survey');
            })
            ->get();

        // Get all related data in batch queries
        $sentDocuments = DocsModel::where('user_id', $clientId)
            ->where('sent_by', $companyId)
            ->get();

        $signedDocuments = Sign::where('user_id', $clientId)
            ->where('sender_id', $companyId)
            ->get();

        $sharedDocuments = ShareDocs::with(['docsData'])
            ->where('user_id', $clientId)
            ->where('business_id', $companyId)
            ->get();

        $verificationDocs = UserVerificationDoc::where('user_id', $clientId)
            ->whereNotNull('completion_id')
            ->select(['id', 'id_path', 'selfie_path', 'verification_date', 'created_at', 'completion_id', 'user_id', 'is_verified'])
            ->get()
            ->groupBy('completion_id');

        // OPTIMIZATION: Process data efficiently
        $documentsA = $documentViewsDocs->map(function ($doc) {
            $type = $doc->status == "survey" ? "Survey" : "Requested";
            return (object) [
                'name' => @$doc->docsData->text,
                'type' => $type,
                'path' => "document/" . @$doc->doc_hash,
                'created_at' => @$doc->created_at,
                'docs_table_id' => @$doc->docsData->id,
                'archived_at' => @$doc->docsData->archived_at,
            ];
        });

        $documentsB = $sentDocuments->map(function ($doc) {
            return (object) [
                'docs_table_id' => $doc->id,
                'name' => $doc->text,
                'type' => "Sent",
                'path' => "sent_document/" . $doc->doc_token,
                'created_at' => $doc->created_at,
            ];
        });

        $documentsC = $signedDocuments->map(function ($doc) {
            return (object) [
                'name' => $doc->name,
                'type' => "Signed",
                'path' => "sign/" . $doc->hash,
                'created_at' => $doc->created_at,
                'docs_table_id' => $doc->hash,
            ];
        });

        $documentsD = $sharedDocuments->map(function ($doc) {
            return (object) [
                'name' => $doc->docsData->text,
                'type' => "Shared",
                'path' => "share_document/" . $doc->id,
                'created_at' => $doc->created_at,
                'docs_table_id' => $doc->docsData->id,
            ];
        });
$documentsE = $verificationDocs->map(function ($verificationDoc) {
    $idDocument = $verificationDoc->where('id_path', '<>', null)->first();
    $selfieDocument = $verificationDoc->where('selfie_path', '<>', null)->first();

    $idPath = $idDocument->id_path ?? null;
    $selfiePath = $selfieDocument->selfie_path ?? null;

    if ($idPath && $selfiePath) {
        $extension = pathinfo($idPath, PATHINFO_EXTENSION);
        $idHash = $extension == 'verime' ? pathinfo(pathinfo($idPath, PATHINFO_FILENAME), PATHINFO_FILENAME) : pathinfo($idPath, PATHINFO_FILENAME);

        $extension = pathinfo($selfiePath, PATHINFO_EXTENSION);
        $imageHash = $extension == 'verime' ? pathinfo(pathinfo($selfiePath, PATHINFO_FILENAME), PATHINFO_FILENAME) : pathinfo($selfiePath, PATHINFO_FILENAME);

        // FIX: Remove Storage disk check - just create the PDF
        AppSupport::createPDFWithIDandSelfie($idDocument, $selfieDocument, $idHash, $imageHash, $selfieDocument->is_verified);
        
        $record = $verificationDoc->first();
        return (object) [
            'name' => 'Verification Doc_' . $record->verification_date,
            'type' => 'Verification',
            'path' => "verification/$idHash-$imageHash?user_id={$record->user_id}&completion_id={$record->completion_id}&id_hash={$idHash}&image_hash={$imageHash}",
            'is_verified' => (bool) $record->is_verified,
            'created_at' => $record->created_at,
            'docs_table_id' => "users/{$record->user_id}/completion_id/{$record->completion_id}/id_hash/{$idHash}/image_hash/{$imageHash}",
        ];
    } else {
        return null;
    }
})->filter();

        // Merge all documents
        $mergedDocuments = $documentsA->concat($documentsB)
            ->concat($documentsC)
            ->concat($documentsD)
            ->concat($documentsE)
            ->unique('docs_table_id');

        // Pagination
        $page = $request->get('page', 1);
        $paginatedDocuments = $this->paginate_array($mergedDocuments, 8, $page, [
            'path' => request()->url() . '?phone=' . $phone
        ]);

        // OPTIMIZATION: Single query for surveys with joins
        $surveysQuery = Survey::select(
                'surveys.*',
                'questionsets.title',
                'questionsets.id as questionSetId',
                'questionsets.type as questionSetType',
                'users.first_name',
                'users.last_name'
            )
            ->join('questionsets', 'questionsets.id', '=', 'surveys.formId')
            ->join('users', 'users.id', '=', 'surveys.userId')
            ->where('questionsets.businessId', $companyId)
            ->where('surveys.userId', $clientId);

        // Apply filters
        if ($request->has('question_set') && !empty($request->question_set)) {
            $surveysQuery->where('questionsets.title', 'LIKE', '%' . $request->question_set . '%');
        }

        if ($request->has('application_status') && !empty($request->application_status)) {
            $surveysQuery->where('surveys.application_status', $request->application_status);
        }

        $surveys = $surveysQuery->orderBy('surveys.created_at', 'desc')->paginate();

        // Get extra details
        $extraDetails = ClientExtraDetail::where('user_id', $clientId)->first();
        $extraDetailsFields = $extraDetails ? json_decode($extraDetails->additional_fields, true) : [];

        return view('client.info', [
            'phone' => $client->phone,
            'clientName' => $client->first_name . ' ' . $client->last_name,
            'documentViewsDocs' => $paginatedDocuments,
            'surveys' => $surveys,
            'extraDetailsFields' => $extraDetailsFields,
        ]);
    }

    public function paginate_array($items, $perPage = 8, $page = null, $options = [])
    {
        $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);
        $items = $items instanceof Collection ? $items : Collection::make($items);
        
        return new LengthAwarePaginator(
            $items->forPage($page, $perPage), 
            $items->count(), 
            $perPage, 
            $page, 
            $options
        );
    }
}
