<?php

namespace App\Controllers;

use Core\Controller;
use Core\Auth;
use App\Models\ErrorLog;

class ErrorLogController extends Controller
{
    private $errorLogModel;

    public function __construct()
    {
        Auth::requireAdmin();
        $this->errorLogModel = new ErrorLog();
    }

    /**
     * Display list of errors with filters
     */
    public function index()
    {
        // Get filters from query params
        $filters = [
            'resolved' => $_GET['resolved'] ?? '',
            'type' => $_GET['type'] ?? '',
            'sort' => $_GET['sort'] ?? 'last_occurred_at',
            'order' => $_GET['order'] ?? 'desc'
        ];

        // Pagination
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
        $offset = ($page - 1) * $perPage;

        // Get total count using model
        $totalErrors = $this->errorLogModel->countUniqueErrors($filters);

        // Get paginated errors using model
        $errors = $this->errorLogModel->getPaginatedErrors($filters, $perPage, $offset);

        // Get statistics using model
        $errorStats = $this->errorLogModel->getAdminStats();

        // Pagination data
        $totalPages = ceil($totalErrors / $perPage);
        $pagination = [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $perPage,
            'total' => $totalErrors,
            'showing_from' => $totalErrors > 0 ? $offset + 1 : 0,
            'showing_to' => min($offset + $perPage, $totalErrors)
        ];

        $data = compact('errors', 'errorStats', 'filters', 'pagination');
        $data['title'] = 'Error Logs';
        $data['pageTitle'] = 'Error Logs';
        $data['pageDescription'] = 'System error tracking and monitoring';
        $data['pageIcon'] = 'fas fa-exclamation-triangle';
        
        $this->view('errors/admin-index', $data);
    }

    /**
     * Show error details
     */
    public function show($params = [])
    {
        $errorId = $params['id'] ?? '';

        // Get all occurrences using model
        $errorOccurrences = $this->errorLogModel->getOccurrencesByErrorId($errorId);

        if (empty($errorOccurrences)) {
            $_SESSION['error'] = 'Error not found';
            header('Location: /errors');
            exit;
        }

        // Get the most recent occurrence for display
        $error = $errorOccurrences[0];

        // Parse JSON fields
        $error['stack_trace_array'] = json_decode($error['stack_trace'], true) ?? [];
        $error['request_data'] = json_decode($error['request_data'], true) ?? [];
        $error['session_data'] = json_decode($error['session_data'], true) ?? [];

        $data = compact('error', 'errorOccurrences');
        $data['title'] = 'Error Details';
        $data['pageTitle'] = 'Error Details';
        $data['pageDescription'] = htmlspecialchars($error['error_type'] ?? 'Error information');
        $data['pageIcon'] = 'fas fa-bug';
        
        $this->view('errors/admin-detail', $data);
    }

    /**
     * Mark error as resolved
     */
    public function markResolved($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /errors');
            exit;
        }

        $this->verifyCsrf('/errors');

        $errorId = $params['id'] ?? '';
        $notes = $_POST['notes'] ?? null;

        // Mark error as resolved using model
        $this->errorLogModel->markErrorResolved($errorId, \Core\Auth::id(), $notes);

        $_SESSION['success'] = 'Error marked as resolved';
        header('Location: /errors/' . urlencode($errorId));
        exit;
    }

    /**
     * Mark error as unresolved
     */
    public function markUnresolved($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /errors');
            exit;
        }

        $this->verifyCsrf('/errors');

        $errorId = $params['id'] ?? '';

        // Mark error as unresolved using model
        $this->errorLogModel->markErrorUnresolved($errorId);

        $_SESSION['success'] = 'Error marked as unresolved';
        header('Location: /errors/' . urlencode($errorId));
        exit;
    }

    /**
     * Delete error and all its occurrences
     */
    public function delete($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /errors');
            exit;
        }

        $this->verifyCsrf('/errors');

        $errorId = $params['id'] ?? '';

        // Delete error using model
        $this->errorLogModel->deleteByErrorId($errorId);

        $_SESSION['success'] = 'Error deleted successfully';
        header('Location: /errors');
        exit;
    }

    /**
     * Clear old resolved errors
     */
    public function clearResolved()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /errors');
            exit;
        }

        $this->verifyCsrf('/errors');

        $daysOld = isset($_POST['days']) ? (int)$_POST['days'] : 30;

        // Clear old errors using model
        $deletedCount = $this->errorLogModel->clearOldResolved($daysOld);

        $_SESSION['success'] = "Deleted $deletedCount resolved error(s) older than $daysOld days";
        header('Location: /errors');
        exit;
    }

    /**
     * Bulk delete errors
     */
    public function bulkDelete()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /errors');
            exit;
        }

        $this->verifyCsrf('/errors');

        $errorIdsJson = $_POST['error_ids'] ?? '[]';
        $errorIds = json_decode($errorIdsJson, true);

        if (empty($errorIds) || !is_array($errorIds)) {
            $_SESSION['error'] = 'No errors selected for deletion';
            header('Location: /errors');
            exit;
        }

        $deletedCount = 0;
        foreach ($errorIds as $errorId) {
            if ($this->errorLogModel->deleteByErrorId($errorId)) {
                $deletedCount++;
            }
        }

        $_SESSION['success'] = "Successfully deleted $deletedCount error(s)";
        header('Location: /errors');
        exit;
    }
}
