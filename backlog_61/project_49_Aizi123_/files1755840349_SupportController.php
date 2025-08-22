<?php



namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Exception;
use App\Models\User;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TicketAttachment;
use Yajra\DataTables\DataTables;

class SupportController extends Controller
{

    // public function index(Request $request)
    // {
    //     return view('admin.support');
    // }
    public function index(Request $request)
    {
        $user = auth()->user();

        if ($user->is_admin) {
            return view('admin.support');
        }

        return view('user.support');
    }


    public function getSupportPageData(Request $request)
    {
        if ($request->ajax()) {
            $records = Ticket::query();
            $filters = json_decode($request->filters, true) ?? [];
            $record = $this->recordFilter($records, $filters);

            if (in_array(Auth::user()->role, [0, 1])) {
                $query = $record->with(['user'])->orderBy('created_at', 'DESC');
                $data['total_tickets'] = Ticket::count();
                $data['total_pending'] = Ticket::where('status', 0)->count();
                $data['total_inprocess'] = Ticket::where('status', 1)->count();
                $data['total_completed'] = Ticket::where('status', 2)->count();
            } else {
                $query = $record->where('user_id', Auth()->user()->id)->with(['user'])->orderBy('created_at', 'DESC');
                $data['total_tickets'] = Ticket::where('user_id', Auth()->user()->id)->count();
                $data['total_pending'] = Ticket::where('user_id', Auth()->user()->id)->where('status', 0)->count();
                $data['total_inprocess'] = Ticket::where('user_id', Auth()->user()->id)->where('status', 1)->count();
                $data['total_completed'] = Ticket::where('user_id', Auth()->user()->id)->where('status', 2)->count();
            }

            return DataTables::of($query)
                ->addColumn('ticket_id', function ($ticket) {
                    return '#00' . $ticket->id;
                })
                ->addColumn('user_name', function ($ticket) {
                    return ($ticket->user->first_name ?? '') . ' ' . ($ticket->user->last_name ?? '');
                })
                ->addColumn('subject', function ($ticket) {
                    return $ticket->subject != null ? $this->trimText($ticket->subject, 20) : '';
                })
                ->addColumn('status', function ($ticket) {
                    if ($ticket->status == 0) {
                        return '<span class="badge bg-warning text-dark">Pending</span>';
                    } else if ($ticket->status == 1) {
                        return '<span class="badge bg-primary">In Process</span>';
                    } else if ($ticket->status == 2) {
                        return '<span class="badge bg-success">Completed</span>';
                    }
                })
                ->addColumn('priority', function ($ticket) {
                    $priority = '';
                    if ($ticket->priority == 1) {
                        $priority = 'Low';
                    } else if ($ticket->priority == 2) {
                        $priority = 'Medium';
                    } else if ($ticket->priority == 3) {
                        $priority = 'High';
                    }
                    return '<span class="priority-high">' . $priority . '</span>';
                })
                ->addColumn('created_date', function ($ticket) {
                    return $this->formatDate($ticket->created_at);
                })
                ->addColumn('actions', function ($ticket) {
                    $completeIcon = $ticket->status != 2 ? 'mark_complete' : '';
                    $completeColor = $ticket->status == 2 ? 'gray' : 'green';
                    $viewIcon = $ticket->status == 2 ? 'fa-eye' : 'fa-reply';

                    return '<div class="text-end">
                        <i class="fa-solid fa-circle-check fs-6 ' . $completeIcon . '" data-id="' . $ticket->id . '" title="Mark Complete" style="color:' . $completeColor . ';"></i>&nbsp;&nbsp;&nbsp;
                        <i class="fa-solid ' . $viewIcon . ' fs-6 view_ticket" onclick="viewTicket(' . $ticket->id . ')" title="Reply Ticket" data-bs-toggle="offcanvas" data-bs-target="#supportTicket_canvas"></i>
                    </div>';
                })
                ->with([
                    'total_tickets' => $data['total_tickets'],
                    'total_pending' => $data['total_pending'],
                    'total_inprocess' => $data['total_inprocess'],
                    'total_completed' => $data['total_completed']
                ])
                ->rawColumns(['status', 'priority', 'actions'])
                ->make(true);
        }

        // For non-AJAX requests, return the counts only
        if (in_array(Auth::user()->role, [0, 1])) {
            $data['total_tickets'] = Ticket::count();
            $data['total_pending'] = Ticket::where('status', 0)->count();
            $data['total_inprocess'] = Ticket::where('status', 1)->count();
            $data['total_completed'] = Ticket::where('status', 2)->count();
        } else {
            $data['total_tickets'] = Ticket::where('user_id', Auth()->user()->id)->count();
            $data['total_pending'] = Ticket::where('user_id', Auth()->user()->id)->where('status', 0)->count();
            $data['total_inprocess'] = Ticket::where('user_id', Auth()->user()->id)->where('status', 1)->count();
            $data['total_completed'] = Ticket::where('user_id', Auth()->user()->id)->where('status', 2)->count();
        }

        return response()->json(['status' => 200, 'data' => $data]);
    }

    public function recordFilter($records, $filters)
    {
        $hasFilters = false; // Track if any filters are applied

        // Apply sorting filters
        if (!empty($filters['sort']['column']) && !empty($filters['sort']['order'])) {
            $records->orderBy($filters['sort']['column'], $filters['sort']['order']);
            $hasFilters = true;
        }

        // Apply role filters
        if (!empty($filters['priority'])) {
            $records->whereIn('priority', $filters['priority']);
            $hasFilters = true;
        } else {
            $records->whereIn('priority', [1, 2, 3]);
        }

        // Apply status filters
        if (isset($filters['status']) && $filters['status'] !== '') {
            $records->whereIn('status', $filters['status']);
            $hasFilters = true;
        }

        // Apply date range filters
        if (!empty($filters['date_range']['start']) && !empty($filters['date_range']['end'])) {
            $startDate = $filters['date_range']['start'];
            $endDate = $filters['date_range']['end'];
            $records->whereDate('created_at', '>=', $startDate);
            $records->whereDate('created_at', '<=', $endDate);
            $hasFilters = true;
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $records->whereHas('user', function ($query) use ($search) {
                $query->where('first_name', 'LIKE', "%$search%")
                    ->orWhere('last_name', 'LIKE', "%$search%")
                    ->orWhere('email', 'LIKE', "%$search%");
            });
            $hasFilters = true;
        }

        return $records;
    }

    private function trimText($text, $limit)
    {
        return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
    }

    private function formatDate($date)
    {
        return date('M d, Y', strtotime($date));
    }

    public function saveTicket(Request $request)
    {

        $validatedData = $request->validate([
            'subject' => 'required',
            'priority' => 'required',
            'message' => 'required',
            'files' => 'nullable|array', // Ensure it's an array of files (optional)
            'files.*' => 'mimes:pdf,doc,docx,jpg,jpeg,png,gif|max:2048', // Allow images, PDFs, and Word files
        ], [
            'files.*.mimes' => 'Only PDF, Word files (DOC, DOCX), and images (JPG, JPEG, PNG, GIF) are allowed.',
            'files.*.max' => 'Each file may not exceed 2MB.',
        ]);

        $user = auth()->user();

        $Ticket = new Ticket();
        $Ticket->user_id = Auth::user()->id;
        $Ticket->subject = $request->subject;
        $Ticket->priority = $request->priority;
        $Ticket->message = $request->message;
        $Ticket->status = '0'; // 0:pending, 1:inprocess, 2:completed
        $Ticket->save();


        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $fileName = 'ticket_' . time() . '_' . $file->getClientOriginalName();
                $filePath = 'uploads/ticket';
                $file->move(public_path($filePath), $fileName);

                $Attachment = new TicketAttachment();
                $Attachment->ticket_id = $Ticket->id;
                $Attachment->attachment_type = '1'; //1:Ticket, 2:Reply
                $Attachment->file_name = $file->getClientOriginalName();
                $Attachment->file_type =  $file->getClientOriginalExtension();
                $Attachment->file_path = url('/') . '/' . $filePath . '/' . $fileName;
                $Attachment->save();
            }
        }

        return response()->json(['status' => 200, 'message' => 'Ticket created Successfully...']);
    }

    public function viewTicket(Request $request)
    {
        $data['ticket'] = Ticket::where('id', $request->id)->with(['attachments', 'replies.attachments'])->first();

        return response()->json(['status' => 200, 'data' => $data]);
    }

    public function saveTicketReply(Request $request)
    {

        $request->validate([
            'ticket_id' => 'required',
            'message' => 'required',
            'files' => 'nullable|array', // Ensure it's an array of files (optional)
            'files.*' => 'mimes:pdf,doc,docx,jpg,jpeg,png,gif|max:2048', // Allow images, PDFs, and Word files
        ], [
            'files.*.mimes' => 'Only PDF, Word files (DOC, DOCX), and images (JPG, JPEG, PNG, GIF) are allowed.',
            'files.*.max' => 'Each file may not exceed 2MB.',
        ]);

        $user = auth()->user();

        if (in_array($user->role, [0, 1])) {   // if admin login and reply to user then it will mark in process
            $Ticket = Ticket::find($request->ticket_id);
            $Ticket->status = '1'; // from lookup type table
            $Ticket->save();
        }

        $TicketReply = new TicketReply();
        $TicketReply->ticket_id = $request->ticket_id;
        $TicketReply->reply_from = $user->role == 2 ? 1 : 0;   //0:admin, 1:user
        $TicketReply->message = $request->message;
        $TicketReply->save();

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $fileName = 'ticket_reply_' . time() . '_' . $file->getClientOriginalName();
                $filePath = 'uploads/ticket/reply';
                $file->move(public_path($filePath), $fileName);

                $Attachment = new TicketAttachment();
                $Attachment->reply_id = $TicketReply->id;
                $Attachment->attachment_type = '2'; //1:Ticket, 2:Reply
                $Attachment->file_name = $file->getClientOriginalName();
                $Attachment->file_type =  $file->getClientOriginalExtension();
                $Attachment->file_path = url('/') . '/' . $filePath . '/' . $fileName;
                $Attachment->save();
            }
        }

        return response()->json(['status' => 200, 'message' => "Reply added successfully..."]);
    }

    public function markTicketStatus(Request $request)
    {
        $ticket = Ticket::where('id', $request->id)->first();

        if ($ticket) {
            $ticket->status = 2;    // completed
            $ticket->save();

            return response()->json(['status' => 200, 'message' => "Ticket mark completed successfully..."]);
        }

        return response()->json(['status' => 400, 'message' => "Record not found..."]);
    }
}
