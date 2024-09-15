<?php

namespace App\Http\Controllers;

use App\Models\Reel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReelController extends Controller
{
    /**
     * Handles the chunked reel upload.
     */
    public function uploadChunk(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:mp4,mov,avi,flv|max:102400',
                'chunkIndex' => 'required|integer',
                'totalChunks' => 'required|integer',
                'reel_id' => 'required|string',
            ]);
    
            $reel = Reel::find($request->input('reel_id'));
    
            if (!$reel) {
                // Create a new reel record if it doesn't exist
                $reel = Reel::create([
                    'user_id' => auth()->id(),
                    'status' => 'uploading',
                ]);
            }
    
            // Store the chunk
            $chunkIndex = $request->input('chunkIndex');
            $totalChunks = $request->input('totalChunks');
            $chunkFile = $request->file('file');
            $reelId = $reel->id;
    
            // Save the chunk to a temporary directory
            Storage::putFileAs('public/reels/' . $reelId, $chunkFile, $chunkIndex);
    
            // If last chunk, merge the chunks and update reel status
            if ($chunkIndex + 1 == $totalChunks) {
                $this->mergeChunks($reelId, $totalChunks, $reel);
            }
    
            return response()->json([
                'message' => 'Chunk uploaded successfully',
                'reel_id' => $reelId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    

    /**
     * Merges all the chunks into a single file.
     */
    private function mergeChunks($reelId, $totalChunks, $reel)
    {
        $finalFilePath = 'public/reels/' . $reelId . '.mp4';
        $finalFileAbsolutePath = storage_path('app/' . $finalFilePath);
        $file = fopen($finalFileAbsolutePath, 'ab');

        // Merge each chunk
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = storage_path('app/public/reels/' . $reelId . '/' . $i);
            $chunk = fopen($chunkPath, 'rb');
            fwrite($file, fread($chunk, filesize($chunkPath)));
            fclose($chunk);
        }

        fclose($file);

        // Clean up chunk files
        Storage::deleteDirectory('public/reels/' . $reelId);

        // Update reel record
        $reel->update([
            'file_name' => $reelId . '.mp4',
            'file_path' => $finalFilePath,
            'status' => 'complete',
        ]);
    }

    /**
     * Stream reel video.
     */
    public function stream($reelId)
    {
        try {
            // Find the reel in the database
            $reel = Reel::find($reelId);
    
            // Check if the reel exists
            if (!$reel) {
                return response()->json([
                    'message' => 'Reel not found in the database.',
                ], 404);
            }
    
            // Check if the file exists in storage
            if (!Storage::exists($reel->file_path)) {
                return response()->json([
                    'message' => 'File not found in storage.',
                ], 404);
            }
    
            // Get the absolute path of the file
            $filePath = storage_path('app/' . $reel->file_path);
    
            // Stream the file to the client
            return response()->file($filePath);
    
        } catch (\Exception $e) {
            // Catch any exceptions and return a 500 error response with details
            return response()->json([
                'message' => 'An error occurred while streaming the reel.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
}
