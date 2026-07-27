# Face Matching Module

## Current Implementation

Face similarity is currently handled inside `apps/ai-verification/app/main.py` using
OpenCV ORB (Oriented FAST and Rotated BRIEF) feature matching as a lightweight proxy.

### Limitations of ORB for Face Matching

ORB is a keypoint descriptor designed for object tracking and SLAM, not face verification.
It compares texture features across the full image rather than detecting and aligning facial
landmarks. This means:

- A selfie of person A against their license photo of person A will score lower than expected
- Poor lighting, angle differences, or background clutter heavily reduce the score
- The 82% threshold is conservative but still not a reliable biometric boundary

### Upgrade Path

For production face verification, integrate one of:

| Library | Model | Notes |
|---------|-------|-------|
| [DeepFace](https://github.com/serengil/deepface) | ArcFace, FaceNet, VGG-Face | Python, easiest drop-in |
| [InsightFace](https://github.com/deepinsight/insightface) | ArcFace R100 | Highest accuracy, needs ONNX runtime |
| [face_recognition](https://github.com/ageitgey/face_recognition) | dlib | Pure Python, simpler but heavier |
| Cloud API | AWS Rekognition, Azure Face | No GPU needed, billed per call |

### Integration Points

The function to replace is `face_similarity_score()` in `apps/ai-verification/app/main.py`.
It must:
1. Accept two `bytes` arguments (raw image data)
2. Return a `float | None` in the range `[0.0, 100.0]`
3. Return `None` when the library is unavailable or images cannot be decoded
4. Catch all exceptions so a face-match failure never crashes the verification request

## Planned Improvements

- Replace ORB with ArcFace via DeepFace
- Add face liveness detection (anti-spoofing) check
- Return per-call confidence along with similarity score
- Cache model weights outside the container image
