<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-6">Invoices</h1>

        <div class="bg-white shadow-md rounded my-6 overflow-x-auto">
            <table class="min-w-max w-full table-auto">
                <thead>
                    <tr class="bg-gray-200 text-gray-600 uppercase text-sm leading-normal">
                        <th class="py-3 px-6 text-left">ID</th>
                        <th class="py-3 px-6 text-left">Invoice Number</th>
                        <th class="py-3 px-6 text-left">Customer</th>
                        <th class="py-3 px-6 text-left">Amount</th>
                        <th class="py-3 px-6 text-left">Status</th>
                        <th class="py-3 px-6 text-left">Due Date</th>
                    </tr>
                </thead>
                <tbody class="text-gray-600 text-sm font-light">
                    @foreach ($invoices as $invoice)
                        <tr class="border-b border-gray-200 hover:bg-gray-100">
                            <td class="py-3 px-6 text-left whitespace-nowrap">
                                {{ $invoice->id }}
                            </td>
                            <td class="py-3 px-6 text-left">
                                {{ $invoice->invoice_number }}
                            </td>
                            <td class="py-3 px-6 text-left">
                                {{ $invoice->customer_name }}<br>
                                <span class="text-xs text-gray-500">{{ $invoice->customer_email }}</span>
                            </td>
                            <td class="py-3 px-6 text-left font-bold">
                                ${{ number_format($invoice->amount, 2) }}
                            </td>
                            <td class="py-3 px-6 text-left">
                                <span class="bg-{{ $invoice->status == 'paid' ? 'green' : ($invoice->status == 'pending' ? 'yellow' : 'red') }}-200 text-{{ $invoice->status == 'paid' ? 'green' : ($invoice->status == 'pending' ? 'yellow' : 'red') }}-600 py-1 px-3 rounded-full text-xs">
                                    {{ ucfirst($invoice->status) }}
                                </span>
                            </td>
                            <td class="py-3 px-6 text-left">
                                {{ $invoice->due_date->format('Y-m-d') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $invoices->links() }}
        </div>
    </div>
</body>
</html>
